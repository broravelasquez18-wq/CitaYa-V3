<?php
declare(strict_types=1);
namespace App;

/**
 * Personal del acceso interno (SCRUM-46): roles admin / employee, gestión de empleados y avance.
 * El avance cuenta solicitudes respondidas (requests.resolved_by) y reportes de error cerrados (support_tickets.closed_by).
 */
final class Staff
{
    public const ROLES = ['admin' => 'Administrador', 'employee' => 'Empleado'];

    public function __construct(private readonly Application $app) {}

    public function find(int $id): ?array
    {
        return $this->app->db->one('SELECT id,email,name,role,phone,active,created_at FROM admins WHERE id=?', [$id]);
    }

    /** Todo el personal con su avance de hoy, del mes y del año. */
    public function all(): array
    {
        $rows = $this->app->db->all('SELECT id,email,name,role,phone,active,created_at FROM admins ORDER BY active DESC, role, name');
        foreach ($rows as &$row) $row['progress'] = $this->progress((int)$row['id']);
        return $rows;
    }

    /**
     * Crea un empleado (o administrador) con una contraseña aleatoria que se muestra una sola vez.
     * @return array{id:int,password:string}
     */
    public function create(string $name, string $email, string $role, string $phone = ''): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new \DomainException('Escribe el nombre completo (entre 3 y 120 caracteres).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new \DomainException('Escribe un correo válido.');
        if (!isset(self::ROLES[$role])) throw new \DomainException('Elige un rol válido.');
        $phone = $this->phone($phone);
        if ($this->app->db->one('SELECT id FROM admins WHERE email=?', [$email])) throw new \DomainException('Ya existe una cuenta con ese correo.');
        $password = self::password();
        $id = $this->app->db->insert('admins', ['email' => $email, 'name' => $name, 'role' => $role, 'phone' => $phone,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => $this->app->now()]);
        $this->app->event(null, 'staff_created', $role . ':' . $id);
        return ['id' => $id, 'password' => $password];
    }

    public function update(int $id, int $actorId, string $name, string $role): void
    {
        $staff = $this->require($id);
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) throw new \DomainException('Escribe el nombre completo (entre 3 y 120 caracteres).');
        if (!isset(self::ROLES[$role])) throw new \DomainException('Elige un rol válido.');
        if ($id === $actorId && $role !== 'admin') throw new \DomainException('No puedes quitarte a ti mismo el rol de administrador.');
        if ($staff['role'] === 'admin' && $role !== 'admin') $this->keepOneAdmin($id);
        $this->app->db->run('UPDATE admins SET name=?,role=? WHERE id=?', [$name, $role, $id]);
    }

    public function setActive(int $id, int $actorId, bool $active): void
    {
        $staff = $this->require($id);
        if ($id === $actorId && !$active) throw new \DomainException('No puedes desactivar tu propia cuenta.');
        if (!$active && $staff['role'] === 'admin') $this->keepOneAdmin($id);
        $this->app->db->run('UPDATE admins SET active=? WHERE id=?', [$active ? 1 : 0, $id]);
        $this->app->event(null, $active ? 'staff_activated' : 'staff_deactivated', (string)$id);
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

    /** Últimas solicitudes respondidas por esta persona (para «Mi avance»). */
    public function recentResponses(int $id, int $limit = 15): array
    {
        return $this->app->db->all('SELECT reference,claimed_name,resolution,resolved_at FROM requests WHERE resolved_by=? ORDER BY resolved_at DESC LIMIT ' . max(1, min(50, $limit)), [$id]);
    }

    private function require(int $id): array
    {
        $staff = $this->find($id);
        if (!$staff) throw new \DomainException('La cuenta no existe.');
        return $staff;
    }

    private function keepOneAdmin(int $leaving): void
    {
        $others = (int)$this->app->db->one("SELECT COUNT(*) n FROM admins WHERE role='admin' AND active=1 AND id<>?", [$leaving])['n'];
        if ($others === 0) throw new \DomainException('Debe quedar al menos un administrador activo.');
    }

    private function phone(string $phone): ?string
    {
        if (trim($phone) === '') return null;
        return WhatsApp::normalizePhone($phone);
    }

    private static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(18)), '+/', 'Kx'), 0, 16);
    }
}
