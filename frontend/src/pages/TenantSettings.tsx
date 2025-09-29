import { FormEvent, useEffect, useMemo, useState } from 'react';
import { DateTime } from 'luxon';
import { useTenantBranding, useUpdateTenantBranding } from '../hooks/useTenantBranding';
import { useTenantOverview } from '../hooks/useTenantOverview';
import { useToast } from '../components/common/ToastProvider';

interface BrandingFormState {
  logoUrl: string;
  primary: string;
  accent: string;
  bg: string;
  text: string;
}

interface UsageAlert {
  id: string;
  message: string;
}

const defaultBranding: BrandingFormState = {
  logoUrl: '',
  primary: '#2563eb',
  accent: '#38bdf8',
  bg: '#020617',
  text: '#f8fafc',
};

const upgradeHref = 'mailto:ventas@monotickets.com?subject=Consulta%20de%20upgrade';

const formatDate = (value: string | null | undefined) => {
  if (!value) {
    return '—';
  }

  return DateTime.fromISO(value).setLocale('es').toLocaleString(DateTime.DATE_MED);
};

const formatCurrency = (valueCents: number) =>
  new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'USD' }).format(valueCents / 100);

const TenantSettings = () => {
  const { data: brandingData, isLoading: brandingLoading } = useTenantBranding();
  const { data: overviewData, isLoading: overviewLoading, refetch: refetchOverview } = useTenantOverview();
  const { showToast } = useToast();

  const [formState, setFormState] = useState<BrandingFormState>(defaultBranding);
  const [showInvoiceDetails, setShowInvoiceDetails] = useState(false);

  const branding = brandingData?.data;
  const overview = overviewData?.data;

  useEffect(() => {
    if (!branding) {
      return;
    }

    setFormState({
      logoUrl: branding.logo_url ?? '',
      primary: branding.colors.primary ?? defaultBranding.primary,
      accent: branding.colors.accent ?? defaultBranding.accent,
      bg: branding.colors.bg ?? defaultBranding.bg,
      text: branding.colors.text ?? defaultBranding.text,
    });
  }, [branding]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    updateBranding({
      logo_url: formState.logoUrl || null,
      colors: {
        primary: formState.primary || null,
        accent: formState.accent || null,
        bg: formState.bg || null,
        text: formState.text || null,
      },
    });
  };

  const { mutate: updateBranding, isPending: isSavingBranding } = useUpdateTenantBranding({
    onSuccess: () => {
      showToast({ message: 'Branding actualizado correctamente', severity: 'success' });
      void refetchOverview();
    },
    onError: () => {
      showToast({ message: 'No se pudo actualizar el branding. Intenta nuevamente.', severity: 'error' });
    },
  });

  const usageAlerts: UsageAlert[] = useMemo(() => {
    if (!overview) {
      return [];
    }

    const alerts: UsageAlert[] = [];
    const limits = overview.effective_limits as Record<string, unknown>;

    const maxEvents = Number(limits.max_events ?? 0);
    if (maxEvents > 0) {
      const ratio = overview.usage.event_count / maxEvents;
      if (ratio >= 0.8) {
        alerts.push({
          id: 'events',
          message: `Has utilizado ${overview.usage.event_count} de ${maxEvents} eventos incluidos en tu plan.`,
        });
      }
    }

    const maxUsers = Number(limits.max_users ?? 0);
    if (maxUsers > 0) {
      const ratio = overview.usage.user_count / maxUsers;
      if (ratio >= 0.8) {
        alerts.push({
          id: 'users',
          message: `Tu equipo alcanza ${overview.usage.user_count} de ${maxUsers} usuarios disponibles.`,
        });
      }
    }

    const maxScansPerEvent = Number(limits.max_scans_per_event ?? 0);
    if (maxScansPerEvent > 0) {
      overview.scan_breakdown.forEach((entry) => {
        const ratio = entry.value / maxScansPerEvent;
        if (ratio >= 0.8) {
          alerts.push({
            id: `event-${entry.event_id}`,
            message: `El evento ${entry.event_name ?? entry.event_id} alcanzó ${entry.value} de ${maxScansPerEvent} escaneos permitidos.`,
          });
        }
      });
    }

    return alerts;
  }, [overview]);

  const usageCards = useMemo(() => {
    if (!overview) {
      return [];
    }

    const limits = overview.effective_limits as Record<string, unknown>;

    return [
      {
        id: 'events',
        label: 'Eventos activos',
        value: overview.usage.event_count,
        limit: Number(limits.max_events ?? 0) || null,
      },
      {
        id: 'users',
        label: 'Usuarios activos',
        value: overview.usage.user_count,
        limit: Number(limits.max_users ?? 0) || null,
      },
    ];
  }, [overview]);

  const scanItems = useMemo(() => {
    if (!overview) {
      return [];
    }

    const limits = overview.effective_limits as Record<string, unknown>;
    const maxScansPerEvent = Number(limits.max_scans_per_event ?? 0) || null;

    return overview.scan_breakdown.map((entry) => ({
      ...entry,
      limit: maxScansPerEvent,
    }));
  }, [overview]);

  return (
    <section className="settings-page">
      <header>
        <h1 style={{ marginTop: 0, marginBottom: '0.5rem' }}>Configuración del tenant</h1>
        <p style={{ color: 'var(--text-secondary)', margin: 0 }}>
          Personaliza tu marca y revisa el estado actual de tu plan, consumo y facturación.
        </p>
      </header>

      <section className="settings-section">
        <div className="settings-section__header">
          <div>
            <h2 style={{ margin: 0 }}>Branding</h2>
            <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
              Ajusta el logo y los colores que se reflejan en tu landing y comunicaciones.
            </p>
          </div>
        </div>

        {brandingLoading ? (
          <p>Cargando configuración de branding…</p>
        ) : (
          <div className="branding-grid">
            <form className="branding-form" onSubmit={handleSubmit}>
              <label>
                <span>Logo (URL)</span>
                <input
                  type="url"
                  value={formState.logoUrl}
                  onChange={(event) => setFormState((prev) => ({ ...prev, logoUrl: event.target.value }))}
                  placeholder="https://cdn.ejemplo.com/logo.png"
                />
              </label>
              <div className="branding-form__colors">
                <label>
                  <span>Primario</span>
                  <input
                    type="color"
                    value={formState.primary}
                    onChange={(event) => setFormState((prev) => ({ ...prev, primary: event.target.value }))}
                  />
                </label>
                <label>
                  <span>Acento</span>
                  <input
                    type="color"
                    value={formState.accent}
                    onChange={(event) => setFormState((prev) => ({ ...prev, accent: event.target.value }))}
                  />
                </label>
                <label>
                  <span>Fondo</span>
                  <input
                    type="color"
                    value={formState.bg}
                    onChange={(event) => setFormState((prev) => ({ ...prev, bg: event.target.value }))}
                  />
                </label>
                <label>
                  <span>Texto</span>
                  <input
                    type="color"
                    value={formState.text}
                    onChange={(event) => setFormState((prev) => ({ ...prev, text: event.target.value }))}
                  />
                </label>
              </div>
              <button type="submit" disabled={isSavingBranding}>
                {isSavingBranding ? 'Guardando…' : 'Guardar cambios'}
              </button>
            </form>

            <div className="branding-preview" style={{ backgroundColor: formState.bg, color: formState.text }}>
              <div className="branding-preview__header">
                {formState.logoUrl ? (
                  <img src={formState.logoUrl} alt="Logo" style={{ maxWidth: '140px', maxHeight: '60px', objectFit: 'contain' }} />
                ) : (
                  <div
                    style={{
                      width: '140px',
                      height: '60px',
                      borderRadius: '0.5rem',
                      background: 'rgba(15, 23, 42, 0.6)',
                      border: '1px dashed rgba(148, 163, 184, 0.35)',
                      display: 'grid',
                      placeItems: 'center',
                      color: 'var(--text-secondary)',
                      fontSize: '0.85rem',
                    }}
                  >
                    Logo
                  </div>
                )}
                <span className="branding-preview__badge" style={{ backgroundColor: formState.accent, color: formState.bg }}>
                  Vista previa
                </span>
              </div>
              <div style={{ display: 'grid', gap: '0.75rem' }}>
                <h3 style={{ margin: 0 }}>Evento destacado</h3>
                <p style={{ margin: 0 }}>
                  Personaliza los colores para que coincidan con la identidad de tu marca. Así tus clientes reconocerán tus
                  eventos al instante.
                </p>
                <button
                  type="button"
                  style={{
                    backgroundColor: formState.primary,
                    color: formState.text,
                    borderColor: formState.accent,
                    justifySelf: 'start',
                  }}
                >
                  Comprar entradas
                </button>
              </div>
            </div>
          </div>
        )}
      </section>

      <section className="settings-section">
        <div className="settings-section__header">
          <div>
            <h2 style={{ margin: 0 }}>Plan & Uso</h2>
            <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
              Controla el plan contratado, tus límites y el consumo en el periodo actual.
            </p>
          </div>
        </div>

        {overviewLoading ? (
          <p>Cargando información de plan…</p>
        ) : overview ? (
          <div className="plan-usage-grid">
            <div className="plan-card">
              <span className="plan-card__label">Plan actual</span>
              <h3 style={{ margin: '0 0 0.5rem 0' }}>{overview.plan ? overview.plan.name : 'Sin plan'}</h3>
              {overview.plan ? (
                <>
                  <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
                    Ciclo: {overview.plan.billing_cycle === 'yearly' ? 'Anual' : 'Mensual'} · {formatCurrency(overview.plan.price_cents)}
                  </p>
                  <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
                    Periodo actual: {formatDate(overview.period.start)} – {formatDate(overview.period.end)}
                  </p>
                </>
              ) : (
                <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Contacta a soporte para activar un plan.</p>
              )}
            </div>

            {usageAlerts.length > 0 && (
              <div className="alert-upgrade">
                <div style={{ display: 'grid', gap: '0.5rem' }}>
                  {usageAlerts.map((alert) => (
                    <p key={alert.id} style={{ margin: 0 }}>
                      {alert.message}
                    </p>
                  ))}
                </div>
                <a className="upgrade-link" href={upgradeHref} target="_blank" rel="noreferrer">
                  Contactar para upgrade
                </a>
              </div>
            )}

            <div className="usage-cards">
              {usageCards.map((card) => {
                const ratio = card.limit && card.limit > 0 ? Math.min(card.value / card.limit, 1) : null;
                return (
                  <div key={card.id} className="usage-card">
                    <div>
                      <span className="usage-card__label">{card.label}</span>
                      <h3 style={{ margin: '0.25rem 0 0 0' }}>{card.value}</h3>
                    </div>
                    {card.limit ? (
                      <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Límite: {card.limit}</p>
                    ) : (
                      <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Sin límite configurado</p>
                    )}
                    {ratio !== null && (
                      <div className="progress">
                        <div className="progress__bar" style={{ width: `${Math.round(ratio * 100)}%` }} />
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            <div className="scan-list">
              <h3 style={{ margin: '0 0 0.5rem 0' }}>Escaneos por evento</h3>
              {scanItems.length === 0 ? (
                <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Aún no registras escaneos en este periodo.</p>
              ) : (
                scanItems.map((item) => {
                  const ratio = item.limit && item.limit > 0 ? Math.min(item.value / item.limit, 1) : null;
                  return (
                    <div key={item.event_id} className="scan-item">
                      <div>
                        <strong>{item.event_name ?? 'Evento sin nombre'}</strong>
                        <p style={{ margin: 0, color: 'var(--text-secondary)' }}>{item.value} escaneos</p>
                      </div>
                      {item.limit ? <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Límite por evento: {item.limit}</p> : null}
                      {ratio !== null && (
                        <div className="progress">
                          <div className="progress__bar" style={{ width: `${Math.round(ratio * 100)}%` }} />
                        </div>
                      )}
                    </div>
                  );
                })
              )}
            </div>
          </div>
        ) : (
          <p>No encontramos información del plan actual.</p>
        )}
      </section>

      <section className="settings-section">
        <div className="settings-section__header">
          <div>
            <h2 style={{ margin: 0 }}>Suscripción</h2>
            <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
              Consulta el estado de la suscripción y accede a la última factura generada.
            </p>
          </div>
        </div>

        {overviewLoading ? (
          <p>Cargando información de suscripción…</p>
        ) : overview ? (
          <div className="subscription-meta">
            <div>
              <span className="plan-card__label">Estado</span>
              <h3 style={{ margin: '0.25rem 0 0 0' }}>{overview.subscription ? overview.subscription.status : 'Sin suscripción'}</h3>
            </div>
            {overview.subscription ? (
              <div className="subscription-meta__dates">
                <span>Inicio de periodo: {formatDate(overview.subscription.current_period_start)}</span>
                <span>Fin de periodo: {formatDate(overview.subscription.current_period_end)}</span>
                {overview.subscription.trial_end ? (
                  <span>Trial hasta: {formatDate(overview.subscription.trial_end)}</span>
                ) : null}
              </div>
            ) : null}

            {overview.latest_invoice ? (
              <div className="subscription-invoice">
                <button type="button" onClick={() => setShowInvoiceDetails((prev) => !prev)}>
                  {showInvoiceDetails ? 'Ocultar factura' : 'Ver factura'}
                </button>
                {showInvoiceDetails ? (
                  <div className="invoice-details">
                    <div>
                      <strong>Periodo facturado</strong>
                      <p style={{ margin: 0, color: 'var(--text-secondary)' }}>
                        {formatDate(overview.latest_invoice.period_start)} – {formatDate(overview.latest_invoice.period_end)}
                      </p>
                    </div>
                    <div className="invoice-details__lines">
                      {overview.latest_invoice.line_items.map((item, index) => (
                        <div key={`${item.type}-${index}`} className="invoice-line">
                          <span>
                            {item.description} · {item.quantity} × {formatCurrency(item.unit_price_cents)}
                          </span>
                          <strong>{formatCurrency(item.amount_cents)}</strong>
                        </div>
                      ))}
                    </div>
                    <div className="invoice-line invoice-line--total">
                      <span>Total</span>
                      <strong>{formatCurrency(overview.latest_invoice.total_cents)}</strong>
                    </div>
                  </div>
                ) : null}
              </div>
            ) : (
              <p style={{ margin: 0, color: 'var(--text-secondary)' }}>Todavía no se generaron facturas para este tenant.</p>
            )}
          </div>
        ) : (
          <p>No se encontró información de suscripción.</p>
        )}
      </section>
    </section>
  );
};

export default TenantSettings;
