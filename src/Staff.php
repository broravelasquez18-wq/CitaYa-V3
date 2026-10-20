<?php
declare(strict_types=1);
namespace App;

/**
 * Personal del acceso interno (SCRUM-46): roles admin / employee, gestión (CRUD) de empleados y avance.
 * La cédula identifica a cada persona: es obligatoria y no se repite. Internamente se conserva el id numérico porque
 * las solicitudes respondidas, los reportes y el avance apuntan a él; así eliminar una cuenta no borra su historial.
 * El avance cuenta solicitudes respondidas (requests.resolved_by) y reportes de error cerrados (support_tickets.closed_by).
 */
final class Staff
{
    public const ROLES = ['admin' => 'Administrador', 'employee' => 'Empleado'];

    public function __construct(private readonly Application $app) {}

    public function find(int $id): ?array
    {
        return $this->app->db->one('SELECT id,document_number,email,name,role,phone,active,created_at FROM admins WHERE id=? AND deleted_at IS NULL', [$id]);
    }

    public function findByDocument(string $document): ?array
    {
        return $this->app->db->one('SELECT id,document_number,email,name,role,phone,active,created_at FROM admins WHERE document_number=? AND deleted_at IS NULL', [self::document($document)]);
    }

    /** Personal no eliminado, con su avance de hoy, del mes y del año. $query busca por cédula, nombre o correo. */
    public function all(string $query = ''): array
    {
        $sql = 'SELECT id,document_number,email,name,role,phone,active,created_at FROM admins WHERE deleted_at IS NULL';
        $params = [];
        $query = trim($query);
        if ($query !== '') {
            $term = '%' . strtr(mb_substr($query, 0, 80), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $sql .= " AND (document_number LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
            $params = [$term, $term, $term];
        }
        $rows = $this->app->db->all($sql . ' ORDER BY active DESC, role, name', $params);
        foreach ($rows as &$row) $row['progress'] = $this->progress((int)$row['id']);
        return $rows;
    }

    /**
     * Crea un empleado (o administrador) con una contraseña aleatoria que se muestra una sola vez.
     * @return array{id:int,password:string}
     */
    public function create(string $document, string $name, string $email, string $role): array
    {
        [$document, $name, $email] = $this->validate($document, $name, $email, $role);
        if ($this->app->db->one('SELECT id FROM admins WHERE document_number=?', [$document])) throw new \DomainException('Ya existe una cuenta con esa cédula.');
        if ($this->app->db->one('SELECT id FROM admins WHERE email=?', [$email])) throw new \DomainException('Ya existe una cuenta con ese correo.');
        $password = self::password();
        $id = $this->app->db->insert('admins', ['document_number' => $document, 'email' => $email, 'name' => $name, 'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => $this->app->now()]);
        $this->app->event(null, 'staff_created', $role . ':' . $id);
        return ['id' => $id, 'password' => $password];
    }

    public function update(int $id, int $actorId, string $document, string $name, string $email, string $role): void
    {
        $staff = $this->require($id);
        [$document, $name, $email] = $this->validate($document, $name, $email, $role);
        if ($this->app->db->one('SELECT id FROM admins WHERE document_number=? AND id<>?', [$document, $id])) throw new \DomainException('Esa cédula ya pertenece a otra cuenta.');
        if ($this->app->db->one('SELECT id FROM admins WHERE email=? AND id<>?', [$email, $id])) throw new \DomainException('Ese correo ya pertenece a otra cuenta.');
        if ($id === $actorId && $role !== 'admin') throw new \DomainException('No puedes quitarte a ti mismo el rol de administrador.');
        if ($staff['role'] === 'admin' && $role !== 'admin') $this->keepOneAdmin($id);
        $this->app->db->run('UPDATE admins SET document_number=?,name=?,email=?,role=? WHERE id=?', [$document, $name, $email, $role, $id]);
        $this->app->event(null, 'staff_updated', (string)$id);
    }

    public function setActive(int $id, int $actorId, bool $active): void
    {
        $staff = $this->require($id);
        if ($id === $actorId && !$active) throw new \DomainException('No puedes desactivar tu propia cuenta.');
        if (!$active && $staff['role'] === 'admin') $this->keepOneAdmin($id);
        $this->app->db->run('UPDATE admins SET active=? WHERE id=?', [$active ? 1 : 0, $id]);
        $this->app->event(null, $active ? 'staff_activated' : 'staff_deactivated', (string)$id);
    }

    /**
     * Elimina una cuenta. Sin actividad se borra del todo; si ya respondió solicitudes o reportes, se marca como
     * eliminada (no aparece ni puede entrar) para conservar el historial y el avance. Sus reportes abiertos pasan a
     * otra persona activa. Devuelve true si se borró del todo.
     */
    public function delete(int $id, int $actorId): bool
    {
        $staff = $this->require($id);
        if ($id === $actorId) throw new \DomainException('No puedes eliminar tu propia cuenta.');
        if ($staff['role'] === 'admin') $this->keepOneAdmin($id);
        return $this->app->db->transaction(function () use ($id) {
            $db = $this->app->db;
            $open = $db->all("SELECT id FROM support_tickets WHERE assigned_to=? AND status='open'", [$id]);
            foreach ($open as $ticket) {
                $next = $db->one("SELECT a.id FROM admins a WHERE a.active=1 AND a.deleted_at IS NULL AND a.id<>? ORDER BY a.role='employee' DESC,
                    (SELECT COUNT(*) FROM support_tickets t WHERE t.assigned_to=a.id AND t.status='open'), a.id LIMIT 1", [$id]);
                $db->run('UPDATE support_tickets SET assigned_to=? WHERE id=?', [$next['id'] ?? null, $ticket['id']]);
            }
            $used = $db->one('SELECT 1 x FROM requests WHERE resolved_by=? OR case_reopened_by=? LIMIT 1', [$id, $id])
                || $db->one('SELECT 1 x FROM support_tickets WHERE assigned_to=? OR closed_by=? LIMIT 1', [$id, $id])
                || $db->one('SELECT 1 x FROM support_messages WHERE admin_id=? LIMIT 1', [$id])
                || $db->one('SELECT 1 x FROM message_templates WHERE updated_by=? LIMIT 1', [$id]);
            if ($used) {
                // El correo y la cédula quedan libres para una cuenta nueva; el historial conserva el nombre.
                $db->run("UPDATE admins SET active=0,deleted_at=?,document_number=NULL,email=CONCAT('eliminado-',id,'@citaya.invalid'),password_hash='!' WHERE id=?", [$this->app->now(), $id]);
                $this->app->event(null, 'staff_deleted', 'archived:' . $id);
                return false;
            }
            $db->run('DELETE FROM admins WHERE id=?', [$id]);
            $this->app->event(null, 'staff_deleted', 'removed:' . $id);
            return true;
        });
    }

    /** Contraseña escrita por el administrador al editar la cuenta. Se valida antes de guardar los demás datos. */
    public static function checkPassword(string $password, string $confirm, string $document = '', string $email = ''): void
    {
        if (trim($password) === '') throw new \DomainException('Escribe la contraseña nueva.');
        // Mínimo de 6 para que sirvan cédulas cortas (decisión del titular, 2026-10-14).
        if (mb_strlen($password) < 6) throw new \DomainException('La contraseña debe tener al menos 6 caracteres.');
        if (strlen($password) > 72) throw new \DomainException('La contraseña es demasiado larga (máximo 72 caracteres).');
        if (!hash_equals($password, $confirm)) throw new \DomainException('La confirmación no coincide con la contraseña nueva.');
        // Por decisión del titular (2026-10-14), por ahora se permite usar la cédula o el correo como contraseña.
    }

    public function setPassword(int $id, string $password, string $confirm): void
    {
        $staff = $this->require($id);
        self::checkPassword($password, $confirm, (string)($staff['document_number'] ?? ''), (string)$staff['email']);
        $this->app->db->run('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        $this->app->event(null, 'staff_password_set', (string)$id);
    }

    /** Nueva contraseña aleatoria (se muestra una sola vez). */
    public function resetPassword(int $id): string
    {
        $this->require($id);
        $password = self::password();
        $this->app->db->run('UPDATE admins SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        $this->app->event(null, 'staff_password_reset', (string)$id);
        return $password;
    }

    /** Avance: solicitudes respondidas y reportes cerrados hoy, en el mes y en el año (hora de Colombia). */
    public function progress(int $id): array
    {
        $ranges = ['day' => date('Y-m-d 00:00:00'), 'month' => date('Y-m-01 00:00:00'), 'year' => date('Y-01-01 00:00:00')];
        $result = [];
        foreach ($ranges as $key => $from) {
            $result[$key] = [
                'requests' => (int)$this->app->db->one('SELECT COUNT(*) n FROM requests WHERE resolved_by=? AND resolved_at>=?', [$id, $from])['n'],
                'reports' => (int)$this->app->db->one("SELECT COUNT(*) n FROM support_tickets WHERE closed_by=? AND status='closed' AND closed_at>=?", [$id, $from])['n'],
            ];
        }
        return $result;
    }

    /** Respuestas de esta persona desde una fecha, por tipo: aprobado, no registra e historia asignada. */
    public function resolutionCounts(int $id, string $from): array
    {
        $counts = ['approved' => 0, 'not_registered' => 0, 'assigned' => 0];
        foreach ($this->app->db->all('SELECT resolution,COUNT(*) n FROM requests WHERE resolved_by=? AND resolved_at>=? GROUP BY resolution', [$id, $from]) as $row) {
            if (isset($counts[$row['resolution']])) $counts[$row['resolution']] = (int)$row['n'];
        }
        return $counts;
    }

    /** Avance completo de una persona para «Mi avance» y «Ver avance» en Empleados; null si la cuenta no existe. */
    public function report(int $id): ?array
    {
        $member = $this->find($id);
        if (!$member) return null;
        return ['member' => $member, 'progress' => $this->progress($id), 'mix' => $this->resolutionCounts($id, date('Y-m-01 00:00:00')), 'recent' => $this->recentResponses($id)];
    }

    /** Últimas solicitudes respondidas por esta persona (para «Mi avance»). */
    public function recentResponses(int $id, int $limit = 15): array
    {
        return $this->app->db->all('SELECT reference,claimed_name,resolution,resolved_at FROM requests WHERE resolved_by=? ORDER BY resolved_at DESC LIMIT ' . max(1, min(50, $limit)), [$id]);
    }

    /** @return array{0:string,1:string,2:string} cédula, nombre y correo normalizados */
    private function validate(string $document, string $name, string $email, string $role): array
    {
        $document = self::document($document);
        if (!preg_match('/^\d{5,12}$/', $document)) throw new \DomainException('Escribe la cédula (solo números, entre 5 y 12 dígitos).');
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new \DomainException('Escribe el nombre completo (entre 3 y 120 caracteres).');
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new \DomainException('Escribe un correo válido.');
        if (!isset(self::ROLES[$role])) throw new \DomainException('Elige un rol válido.');
        return [$document, $name, $email];
    }

    /** «1.088.255.893» → «1088255893». */
    private static function document(string $value): string
    {
        return preg_replace('/[\s.\-]/', '', trim($value));
    }

    private function require(int $id): array
    {
        $staff = $this->find($id);
        if (!$staff) throw new \DomainException('La cuenta no existe.');
        return $staff;
    }

    private function keepOneAdmin(int $leaving): void
    {
        $others = (int)$this->app->db->one("SELECT COUNT(*) n FROM admins WHERE role='admin' AND active=1 AND deleted_at IS NULL AND id<>?", [$leaving])['n'];
        if ($others === 0) throw new \DomainException('Debe quedar al menos un administrador activo.');
    }

    private static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), 0, 16);
    }
}
