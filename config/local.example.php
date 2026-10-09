<?php
// Copy SMTP settings into config/local.php after running bin/setup.php.
// Keep the generated app_key and existing database settings.
return [
    'mail_transport' => 'smtp',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_encryption' => 'tls',
    'smtp_user' => 'YOUR_SMTP_ACCOUNT',
    'smtp_password' => 'YOUR_PROVIDER_APP_PASSWORD',
    'mail_from' => 'YOUR_SMTP_ACCOUNT',
    'test_recipient' => 'broravelasquez18@gmail.com',
    // WhatsApp por Zernio (ver README → «Conectar con Zernio»)
    'whatsapp_transport' => 'zernio',
    'zernio_api_key' => 'API_KEY_DE_ZERNIO',
    'zernio_account_id' => 'ID_DE_LA_CUENTA (php bin/zernio.php accounts)',
    'zernio_webhook_secret' => 'CADENA_ALEATORIA_DE_32_O_MAS_CARACTERES',
    // Alternativa: WhatsApp Cloud API directa (Meta for Developers → WhatsApp → Configuración de la API)
    // con 'whatsapp_transport' => 'cloud' y estos valores:
    'whatsapp_phone_number_id' => 'ID_DEL_NUMERO',
    'whatsapp_token' => 'TOKEN_PERMANENTE_DEL_USUARIO_DEL_SISTEMA',
    'whatsapp_verify_token' => 'UNA_CADENA_ALEATORIA_QUE_TU_ELIJAS',
    'whatsapp_app_secret' => 'CLAVE_SECRETA_DE_LA_APP',
    'public_url' => 'https://tu-dominio-o-tunel/CitaYaV3/public',
];
