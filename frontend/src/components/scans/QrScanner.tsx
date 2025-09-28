import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { BrowserMultiFormatReader, NotFoundException, type IScannerControls } from '@zxing/browser';
import { useMutation } from '@tanstack/react-query';
import { DateTime } from 'luxon';
import { scanTicket, type ScanRequest, type ScanResponsePayload } from '../../api/scan';
import { extractApiErrorMessage } from '../../utils/apiErrors';

const RESULT_VARIANT: Record<string, 'valid' | 'warning' | 'invalid' | 'info'> = {
  valid: 'valid',
  duplicate: 'warning',
  expired: 'warning',
  invalid: 'invalid',
  revoked: 'invalid',
};

const RESULT_LABEL: Record<string, string> = {
  valid: 'Entrada válida',
  duplicate: 'Duplicado',
  expired: 'Expirado',
  invalid: 'Inválido',
  revoked: 'Revocado',
};

interface QrScannerProps {
  eventId?: string | null;
  checkpointId?: string | null;
  deviceId?: string | null;
  debounceMs?: number;
}

type MutableScanHandler = (value: string) => void;

type AudioContextLike = AudioContext | (AudioContext & { close: () => Promise<void> });

const DEFAULT_DEBOUNCE_MS = 2000;

const QrScanner = ({ eventId, checkpointId, deviceId, debounceMs = DEFAULT_DEBOUNCE_MS }: QrScannerProps) => {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const codeReaderRef = useRef<BrowserMultiFormatReader | null>(null);
  const controlsRef = useRef<IScannerControls | null>(null);
  const handleScanRef = useRef<MutableScanHandler>(() => undefined);
  const lastValueRef = useRef<string | null>(null);
  const lastTimestampRef = useRef<number>(0);
  const audioContextRef = useRef<AudioContextLike | null>(null);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [manualCode, setManualCode] = useState('');
  const [ignoredMessage, setIgnoredMessage] = useState<string | null>(null);
  const [lastResult, setLastResult] = useState<ScanResponsePayload | null>(null);
  const [lastError, setLastError] = useState<string | null>(null);

  const scanMutation = useMutation({
    mutationFn: (payload: ScanRequest) => scanTicket(payload),
    onSuccess: (response) => {
      setLastResult(response.data);
      setLastError(null);
      setIgnoredMessage(null);
      playSoundForResult(response.data.result);
    },
    onError: (error) => {
      const message = extractApiErrorMessage(error, 'No se pudo registrar el escaneo.');
      setLastResult(null);
      setLastError(message);
      setIgnoredMessage(null);
      playSoundForResult('invalid');
    },
  });

  const mutateRef = useRef(scanMutation.mutate);
  const isPendingRef = useRef(scanMutation.isPending);

  useEffect(() => {
    mutateRef.current = scanMutation.mutate;
  }, [scanMutation.mutate]);

  useEffect(() => {
    isPendingRef.current = scanMutation.isPending;
  }, [scanMutation.isPending]);

  const ensureAudioContext = useCallback((): AudioContextLike | null => {
    if (typeof window === 'undefined') {
      return null;
    }

    if (!audioContextRef.current) {
      const ContextClass =
        window.AudioContext || (window as typeof window & { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
      if (!ContextClass) {
        return null;
      }
      audioContextRef.current = new ContextClass();
    }

    if (audioContextRef.current.state === 'suspended') {
      void audioContextRef.current.resume();
    }

    return audioContextRef.current;
  }, []);

  const playTone = useCallback(
    (frequency: number, duration: number, delay = 0) => {
      const audioContext = ensureAudioContext();
      if (!audioContext) {
        return;
      }

      const startTime = audioContext.currentTime + delay / 1000;
      const oscillator = audioContext.createOscillator();
      const gainNode = audioContext.createGain();

      oscillator.type = 'sine';
      oscillator.frequency.value = frequency;
      oscillator.connect(gainNode);
      gainNode.connect(audioContext.destination);

      gainNode.gain.setValueAtTime(0.001, startTime);
      gainNode.gain.exponentialRampToValueAtTime(0.3, startTime + 0.01);
      gainNode.gain.exponentialRampToValueAtTime(0.0001, startTime + duration / 1000);

      oscillator.start(startTime);
      oscillator.stop(startTime + duration / 1000 + 0.05);
    },
    [ensureAudioContext]
  );

  const playSoundForResult = useCallback(
    (result: string) => {
      const variant = RESULT_VARIANT[result] ?? 'info';

      switch (variant) {
        case 'valid':
          playTone(880, 180);
          break;
        case 'warning':
          playTone(600, 160);
          playTone(420, 160, 180);
          break;
        case 'invalid':
          playTone(220, 300);
          break;
        default:
          playTone(520, 200);
          break;
      }
    },
    [playTone]
  );

  useEffect(() => {
    return () => {
      if (audioContextRef.current && audioContextRef.current.state !== 'closed') {
        void audioContextRef.current.close();
      }
    };
  }, []);

  const handleScannedValue = useCallback(
    (rawValue: string) => {
      const normalized = rawValue.trim();
      if (!normalized) {
        return;
      }

      const now = Date.now();
      if (lastValueRef.current === normalized && now - lastTimestampRef.current < debounceMs) {
        setIgnoredMessage(`Lectura repetida ignorada (${normalized}).`);
        return;
      }

      if (isPendingRef.current) {
        return;
      }

      lastValueRef.current = normalized;
      lastTimestampRef.current = now;
      setLastError(null);
      setIgnoredMessage(null);

      const payload: ScanRequest = {
        qr_code: normalized,
        scanned_at: new Date(now).toISOString(),
        checkpoint_id: checkpointId ?? null,
        device_id: deviceId ?? null,
      };

      if (eventId !== undefined) {
        payload.event_id = eventId;
      }

      mutateRef.current(payload);
    },
    [checkpointId, deviceId, eventId, debounceMs]
  );

  useEffect(() => {
    handleScanRef.current = handleScannedValue;
  }, [handleScannedValue]);

  useEffect(() => {
    if (typeof navigator === 'undefined') {
      setCameraError('La cámara no está disponible en este entorno.');
      return;
    }

    const startCamera = async () => {
      const videoElement = videoRef.current;
      if (!videoElement) {
        return;
      }

      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        setCameraError('Tu navegador no soporta el acceso a la cámara.');
        return;
      }

      videoElement.playsInline = true;
      videoElement.muted = true;
      videoElement.autoplay = true;

      if (!codeReaderRef.current) {
        codeReaderRef.current = new BrowserMultiFormatReader();
      }

      const codeReader = codeReaderRef.current;
      controlsRef.current?.stop();
      codeReader.reset();

      try {
        const controls = await codeReader.decodeFromVideoDevice(
          undefined,
          videoElement,
          (result, error, controlsParam) => {
            if (controlsParam) {
              controlsRef.current = controlsParam;
            }

            if (result) {
              handleScanRef.current(result.getText());
            }

            if (error && !(error instanceof NotFoundException)) {
              console.error('Error de escaneo', error);
            }
          }
        );

        controlsRef.current = controls;
        setCameraError(null);
      } catch (error) {
        console.error('No se pudo iniciar la cámara', error);
        setCameraError('No se pudo acceder a la cámara. Verifica los permisos del navegador.');
      }
    };

    void startCamera();

    return () => {
      controlsRef.current?.stop();
      codeReaderRef.current?.reset();
    };
  }, []);

  useEffect(() => {
    if (!ignoredMessage) {
      return;
    }

    if (typeof window === 'undefined') {
      return;
    }

    const timeout = window.setTimeout(() => {
      setIgnoredMessage(null);
    }, 2000);

    return () => {
      window.clearTimeout(timeout);
    };
  }, [ignoredMessage]);

  const lastResultVariant = useMemo(() => {
    if (!lastResult) {
      return 'info' as const;
    }
    return RESULT_VARIANT[lastResult.result] ?? 'info';
  }, [lastResult]);

  const lastResultLabel = useMemo(() => {
    if (!lastResult) {
      return '';
    }
    return RESULT_LABEL[lastResult.result] ?? lastResult.result.toUpperCase();
  }, [lastResult]);

  const lastAttendanceTime = useMemo(() => {
    if (!lastResult?.attendance?.scanned_at) {
      return null;
    }

    const parsed = DateTime.fromISO(lastResult.attendance.scanned_at);
    if (!parsed.isValid) {
      return null;
    }
    return parsed.toFormat("dd/MM/yyyy HH:mm:ss");
  }, [lastResult]);

  const handleManualSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!manualCode.trim()) {
      return;
    }
    const value = manualCode;
    setManualCode('');
    handleScannedValue(value);
  };

  return (
    <div className="qr-scanner">
      <div className="qr-scanner__video">
        <video ref={videoRef} autoPlay muted playsInline />
      </div>

      {cameraError && <p className="qr-scanner__status qr-scanner__status--error">{cameraError}</p>}

      <form className="qr-scanner__manual" onSubmit={handleManualSubmit}>
        <label htmlFor="qr-scanner-manual-input">Ingreso manual</label>
        <input
          id="qr-scanner-manual-input"
          type="text"
          inputMode="text"
          autoComplete="off"
          placeholder="Escanea con lector o ingresa el código y presiona Enter"
          value={manualCode}
          onChange={(event) => setManualCode(event.target.value)}
          disabled={scanMutation.isPending}
        />
        <small>Compatible con lectores láser o teclado. Presiona Enter para enviar.</small>
      </form>

      {scanMutation.isPending && <p className="qr-scanner__status">Procesando escaneo...</p>}
      {ignoredMessage && <p className="qr-scanner__status qr-scanner__status--muted">{ignoredMessage}</p>}

      {lastResult && (
        <div className={`scan-result-card scan-result-card--${lastResultVariant}`}>
          <span className="scan-result-card__badge">{lastResultLabel}</span>
          <h3 className="scan-result-card__guest">
            {lastResult.ticket?.guest?.full_name ?? 'Invitado sin nombre'}
          </h3>
          <p className="scan-result-card__message">{lastResult.message}</p>
          <div className="scan-result-card__meta">
            <span>Código: {lastResult.qr_code}</span>
            {lastAttendanceTime && <span>Último escaneo: {lastAttendanceTime}</span>}
          </div>
          {lastResult.reason && (
            <p className="scan-result-card__reason">Código interno: {lastResult.reason}</p>
          )}
        </div>
      )}

      {lastError && (
        <div className="scan-result-card scan-result-card--error">
          <span className="scan-result-card__badge">Error</span>
          <p className="scan-result-card__message">{lastError}</p>
        </div>
      )}
    </div>
  );
};

export default QrScanner;
