<?php

class AuditHelper
{
    public static function log(PDO $db, string $action, string $resourceType, ?string $resourceId, ?array $meta = null): void
    {
        try {
            $actorPessoa = null;
            $actorFunc = null;
            if (class_exists('Auth')) {
                Auth::startSession();
                $actorPessoa = Auth::pessoaId();
                $actorFunc = Auth::funcionarioId();
            }
            $sql = "INSERT INTO audit_log (action, resource_type, resource_id, actor_pessoa_id, actor_funcionario_id, meta)
                    VALUES (:a, :rt, :rid, :ap, :af, :meta)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':a' => $action,
                ':rt' => $resourceType,
                ':rid' => $resourceId,
                ':ap' => $actorPessoa,
                ':af' => $actorFunc,
                ':meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[AuditHelper] Falha a registar auditoria: ' . $e->getMessage());
        }
    }
}
