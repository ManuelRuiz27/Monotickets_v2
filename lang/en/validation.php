<?php

return [
    'generic_error' => 'Los datos proporcionados no son válidos.',

    'event' => [
        'organizer_user_id' => [
            'required' => 'El organizador es obligatorio.',
            'ulid' => 'El organizador seleccionado no es válido.',
            'exists' => 'El organizador seleccionado no existe.',
        ],
        'code' => [
            'required' => 'El código del evento es obligatorio.',
            'max' => 'El código del evento no puede superar los 100 caracteres.',
            'unique' => 'El código del evento ya está en uso para este tenant.',
        ],
        'name' => [
            'required' => 'El nombre del evento es obligatorio.',
        ],
        'timezone' => 'La zona horaria debe ser un identificador IANA válido.',
        'start_at' => [
            'required' => 'La fecha de inicio es obligatoria.',
            'date' => 'La fecha de inicio debe tener un formato válido.',
        ],
        'end_at' => [
            'required' => 'La fecha de finalización es obligatoria.',
            'date' => 'La fecha de finalización debe tener un formato válido.',
            'after' => 'La fecha de finalización debe ser posterior a la fecha de inicio.',
        ],
        'status' => [
            'required' => 'El estado del evento es obligatorio.',
            'in' => 'El estado seleccionado no es válido.',
        ],
        'checkin_policy' => [
            'required' => 'La política de check-in es obligatoria.',
            'in' => 'La política de check-in seleccionada no es válida.',
        ],
        'tenant_id' => [
            'ulid' => 'El tenant seleccionado no es válido.',
            'exists' => 'El tenant seleccionado no existe.',
        ],
    ],

    'venue' => [
        'name' => [
            'required' => 'El nombre del recinto es obligatorio.',
        ],
        'lat' => [
            'numeric' => 'La latitud debe ser un número.',
            'between' => 'La latitud debe estar entre -90 y 90 grados.',
        ],
        'lng' => [
            'numeric' => 'La longitud debe ser un número.',
            'between' => 'La longitud debe estar entre -180 y 180 grados.',
        ],
    ],

    'checkpoint' => [
        'name' => [
            'required' => 'El nombre del punto de control es obligatorio.',
        ],
        'event_id' => [
            'required' => 'El evento es obligatorio.',
            'uuid' => 'El identificador del evento no es válido.',
            'exists' => 'El evento seleccionado no existe.',
            'mismatch' => 'El evento enviado no coincide con la ruta solicitada.',
        ],
        'venue_id' => [
            'required' => 'El recinto es obligatorio.',
            'uuid' => 'El identificador del recinto no es válido.',
            'exists' => 'El recinto seleccionado no pertenece al evento indicado.',
            'mismatch' => 'El recinto enviado no coincide con la ruta solicitada.',
        ],
    ],
];
