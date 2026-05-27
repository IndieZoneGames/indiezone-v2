<?php
class SystemLogger
{
    private $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function log(string $action, string $severity = 'INFO', array $context = []): bool
    {
        $ipAddress = $this->getRealIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $sessionId = session_id();

        $userId      = $context['user_id'] ?? null;
        $entityTable = $context['entity_table'] ?? null;
        $entityId    = $context['entity_id'] ?? null;
        $oldData     = isset($context['old_data']) ? json_encode($context['old_data'], JSON_UNESCAPED_UNICODE) : null;
        $newData     = isset($context['new_data']) ? json_encode($context['new_data'], JSON_UNESCAPED_UNICODE) : null;

        $sql = "INSERT INTO system_logs (user_id, session_id, action, entity_table, entity_id, old_data, new_data, severity, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        try {
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) return false;
            // 'isssisssss' -> i(int), s(string). O user_id e entity_id podem ser nulos, mas o bind_param aceita variáveis nulas se mapeadas como string/int.
            $stmt->bind_param("isssisssss", $userId, $sessionId, $action, $entityTable, $entityId, $oldData, $newData, $severity, $ipAddress, $userAgent);
            $stmt->execute();
            return true;
        } catch (Throwable $e) {
            error_log("FALHA CRÍTICA NO SYSTEM_LOGGER: " . $e->getMessage());
            return false;
        }
    }

    private function getRealIpAddress(): ?string
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
?>