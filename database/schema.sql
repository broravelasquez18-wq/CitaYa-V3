CREATE TABLE IF NOT EXISTS patients (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_type VARCHAR(8) NOT NULL,
 document_number VARCHAR(24) NOT NULL,
 full_name VARCHAR(160) NOT NULL,
 email VARCHAR(190) NOT NULL,
 email_verified_at DATETIME NULL,
 phone VARCHAR(25) NULL,
 active TINYINT NOT NULL DEFAULT 1,
 UNIQUE KEY patient_identity (document_type, document_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS specialties (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE,
 active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS encounters (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 patient_id BIGINT UNSIGNED NOT NULL,
 specialty_id BIGINT UNSIGNED NOT NULL,
 attended_at DATETIME NOT NULL,
 admission VARCHAR(60) NOT NULL,
 folio VARCHAR(60) NULL,
 location VARCHAR(160) NOT NULL,
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (specialty_id) REFERENCES specialties(id),
 INDEX encounter_search (patient_id, attended_at, specialty_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 encounter_id BIGINT UNSIGNED NOT NULL,
 version INT NOT NULL DEFAULT 1,
 status VARCHAR(24) NOT NULL DEFAULT 'incomplete',
 published_at DATETIME NULL,
 FOREIGN KEY (encounter_id) REFERENCES encounters(id),
 UNIQUE KEY package_version (encounter_id, version)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clinical_files (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT UNSIGNED NOT NULL,
 storage_name VARCHAR(80) NOT NULL UNIQUE,
 original_name VARCHAR(190) NOT NULL,
 category VARCHAR(40) NOT NULL DEFAULT 'anexo',
 sort_order INT NOT NULL,
 size_bytes BIGINT NOT NULL,
 page_count INT NULL,
 sha256 CHAR(64) NOT NULL,
 FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(40) NOT NULL UNIQUE,
 patient_id BIGINT UNSIGNED NULL,
 claimed_name VARCHAR(160) NOT NULL,
 claimed_document_type VARCHAR(8) NULL,
 claimed_document_number VARCHAR(24) NULL,
 contact_email VARCHAR(190) NULL,
 whatsapp_phone VARCHAR(20) NULL,
 whatsapp_claimed VARCHAR(20) NULL,
 whatsapp_code_hash CHAR(64) NULL,
 whatsapp_code_expires DATETIME NULL,
 direct_search TINYINT NOT NULL DEFAULT 0,
 matched_at DATETIME NULL,
 case_reopened_at DATETIME NULL,
 case_reopened_by BIGINT UNSIGNED NULL,
 case_reopened_after_job_id BIGINT UNSIGNED NULL,
 resolved_by BIGINT UNSIGNED NULL,
 resolved_at DATETIME NULL,
 resolution VARCHAR(20) NULL,
 response_note TEXT NULL,
 approximate_date DATE NULL,
 specialty_id BIGINT UNSIGNED NULL,
 encounter_id BIGINT UNSIGNED NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'pending_verification',
 verified_email VARCHAR(190) NULL,
 verified_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (patient_id) REFERENCES patients(id),
 FOREIGN KEY (specialty_id) REFERENCES specialties(id),
 FOREIGN KEY (encounter_id) REFERENCES encounters(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 code_hash VARCHAR(255) NOT NULL,
 destination VARCHAR(190) NOT NULL,
 expires_at DATETIME NOT NULL,
 attempts INT NOT NULL DEFAULT 0,
 consumed_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id),
 INDEX verification_request (request_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 verification_id BIGINT UNSIGNED NULL,
 package_id BIGINT UNSIGNED NULL,
 kind VARCHAR(16) NOT NULL,
 recipient VARCHAR(190) NOT NULL,
 encrypted_payload TEXT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'queued',
 attempts INT NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 locked_at DATETIME NULL,
 sent_at DATETIME NULL,
 message_id VARCHAR(190) NOT NULL,
 dedupe_key VARCHAR(100) NOT NULL UNIQUE,
 last_error VARCHAR(120) NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id),
 FOREIGN KEY (verification_id) REFERENCES verifications(id),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 INDEX queue_ready (status, available_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NULL,
 action VARCHAR(60) NOT NULL,
 result VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) PRIMARY KEY,
 hits INT NOT NULL,
 expires_at BIGINT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 document_number VARCHAR(20) NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1,
 role VARCHAR(16) NOT NULL DEFAULT 'admin',
 phone VARCHAR(20) NULL,
 created_at DATETIME NULL,
 deleted_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS whatsapp_inbound (
 wa_message_id VARCHAR(190) PRIMARY KEY,
 phone VARCHAR(20) NOT NULL,
 intent VARCHAR(20) NOT NULL,
 created_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Conversación del proveedor (Zernio) por número: sus respuestas se envían a esa conversación.
CREATE TABLE IF NOT EXISTS whatsapp_conversations (
 phone VARCHAR(20) PRIMARY KEY,
 conversation_id VARCHAR(190) NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS whatsapp_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 token_hash CHAR(64) NOT NULL UNIQUE,
 phone VARCHAR(20) NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 request_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id)
) ENGINE=InnoDB;

-- Estado del sistema (latido del procesador) para el monitoreo desde n8n.
CREATE TABLE IF NOT EXISTS system_state (
 name VARCHAR(60) PRIMARY KEY,
 value VARCHAR(190) NOT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Eventos para n8n (ver src/Automation.php). Solo radicados e identificadores, sin datos clínicos.
CREATE TABLE IF NOT EXISTS automation_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event VARCHAR(60) NOT NULL,
 payload TEXT NOT NULL,
 dedupe_key CHAR(64) NOT NULL UNIQUE,
 status VARCHAR(16) NOT NULL DEFAULT 'queued',
 attempts INT NOT NULL DEFAULT 0,
 last_error VARCHAR(60) NULL,
 available_at DATETIME NOT NULL,
 sent_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 INDEX automation_ready (status, available_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS whatsapp_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NULL,
 package_id BIGINT UNSIGNED NULL,
 phone VARCHAR(20) NOT NULL,
 kind VARCHAR(16) NOT NULL,
 encrypted_body TEXT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'queued',
 attempts INT NOT NULL DEFAULT 0,
 progress INT NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 locked_at DATETIME NULL,
 sent_at DATETIME NULL,
 dedupe_key VARCHAR(100) NOT NULL UNIQUE,
 last_error VARCHAR(120) NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 INDEX whatsapp_ready (status, available_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clinical_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 file_id BIGINT UNSIGNED NOT NULL,
 order_type VARCHAR(20) NOT NULL,
 page INT NOT NULL,
 details TEXT NULL,
 FOREIGN KEY (file_id) REFERENCES clinical_files(id),
 INDEX order_file (file_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_templates (
 code VARCHAR(40) PRIMARY KEY,
 body TEXT NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Plantillas que crea el administrador: respuestas a solicitudes (Aprobado / No Registra) y respuestas rápidas del chat de reportes.
CREATE TABLE IF NOT EXISTS custom_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL,
 usage_code VARCHAR(20) NOT NULL,
 body TEXT NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 updated_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY custom_template_name (usage_code, name)
) ENGINE=InnoDB;

-- Archivos que la persona encargada adjunta al responder una solicitud (Aprobado / No Registra).
-- job_id: correo que los lleva; wa_job_id: mensaje de WhatsApp que los lleva.
CREATE TABLE IF NOT EXISTS response_files (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 job_id BIGINT UNSIGNED NULL,
 wa_job_id BIGINT UNSIGNED NULL,
 storage_name VARCHAR(80) NOT NULL,
 original_name VARCHAR(190) NOT NULL,
 mime VARCHAR(40) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id),
 INDEX response_job (job_id),
 INDEX response_wa_job (wa_job_id)
) ENGINE=InnoDB;

-- Reportes de error de la historia clínica (SCRUM-47/48). status: auto_resolved (lo resolvió el bot), open, closed.
CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reference VARCHAR(24) NOT NULL UNIQUE,
 phone VARCHAR(20) NOT NULL,
 request_id BIGINT UNSIGNED NULL,
 category VARCHAR(30) NOT NULL,
 description TEXT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'open',
 assigned_to BIGINT UNSIGNED NULL,
 unread INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 closed_at DATETIME NULL,
 closed_by BIGINT UNSIGNED NULL,
 FOREIGN KEY (request_id) REFERENCES requests(id),
 INDEX ticket_phone (phone, status),
 INDEX ticket_assigned (assigned_to, status)
) ENGINE=InnoDB;

-- Mensajes del chat de un reporte. direction: in (paciente), out (personal), system (bot). body cifrado.
CREATE TABLE IF NOT EXISTS support_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 direction VARCHAR(8) NOT NULL,
 admin_id BIGINT UNSIGNED NULL,
 body TEXT NOT NULL,
 wa_message_id VARCHAR(190) NULL,
 attachment_storage VARCHAR(80) NULL,
 attachment_name VARCHAR(190) NULL,
 attachment_mime VARCHAR(40) NULL,
 attachment_size BIGINT UNSIGNED NULL,
 attachment_sha256 CHAR(64) NULL,
 outbox_id BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL,
 FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
 INDEX message_ticket (ticket_id, id)
) ENGINE=InnoDB;

-- Paso de la conversación guiada del bot (reporte de error) por número.
CREATE TABLE IF NOT EXISTS whatsapp_sessions (
 phone VARCHAR(20) PRIMARY KEY,
 state VARCHAR(30) NOT NULL,
 data TEXT NULL,
 updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- Datos que el paciente declaró en su última solicitud (formulario web o bot). El bot de WhatsApp los busca por
-- número de documento para no volver a pedirlos: solo le pide confirmarlos y los datos de la atención.
CREATE TABLE IF NOT EXISTS patient_profiles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_type VARCHAR(8) NOT NULL,
 document_number VARCHAR(24) NOT NULL,
 full_name VARCHAR(160) NOT NULL,
 email VARCHAR(190) NOT NULL,
 whatsapp_phone VARCHAR(20) NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY profile_identity (document_type, document_number),
 INDEX profile_document (document_number)
) ENGINE=InnoDB;
