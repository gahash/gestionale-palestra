<?php
declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Database;

/**
 * Livello di integrazione WhatsApp Business, a provider intercambiabile (Meta Cloud API o Twilio).
 * Finche' WHATSAPP_ENABLED=false nel .env, i messaggi vengono solo registrati in coda/log
 * (nessuna chiamata esterna): utile per usare subito il resto del gestionale senza le API pronte.
 */
final class WhatsappService
{
    public static function invia(int $clienteId, string $telefono, string $chiaveTemplate, array $placeholders, ?int $inviatoDa): array
    {
        $pdo = Database::pdo();
        $tpl = $pdo->prepare('SELECT * FROM whatsapp_templates WHERE tenant_id = ? AND chiave = ? AND attivo = 1');
        $tpl->execute([Database::tenantId(), $chiaveTemplate]);
        $template = $tpl->fetch();

        $testo = $template['testo'] ?? '{{messaggio}}';
        foreach ($placeholders as $k => $v) {
            $testo = str_replace('{{' . $k . '}}', (string) $v, $testo);
        }

        $stato = 'in_coda';
        $risposta = null;

        if (Config::bool('WHATSAPP_ENABLED', false)) {
            try {
                $risposta = self::invioProvider($telefono, $testo);
                $stato = 'inviato';
            } catch (\Throwable $e) {
                $stato = 'errore';
                $risposta = $e->getMessage();
            }
        } else {
            $stato = 'disabilitato';
            $risposta = 'WHATSAPP_ENABLED=false: messaggio registrato ma non inviato. Configura le API nel file .env per attivare l\'invio reale.';
        }

        $log = $pdo->prepare(
            'INSERT INTO whatsapp_log (tenant_id, cliente_id, template_id, telefono, messaggio, stato, provider_response, inviato_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $log->execute([
            Database::tenantId(), $clienteId, $template['id'] ?? null, $telefono, $testo, $stato, $risposta, $inviatoDa,
        ]);

        return ['stato' => $stato, 'messaggio' => $testo, 'dettaglio' => $risposta];
    }

    /** @throws \RuntimeException se la chiamata al provider fallisce */
    private static function invioProvider(string $telefono, string $testo): string
    {
        $provider = Config::get('WHATSAPP_PROVIDER', 'meta');

        if ($provider === 'twilio') {
            $sid = Config::get('WHATSAPP_TWILIO_SID');
            $token = Config::get('WHATSAPP_TWILIO_TOKEN');
            $from = Config::get('WHATSAPP_TWILIO_FROM');
            if (!$sid || !$token || !$from) {
                throw new \RuntimeException('Credenziali Twilio mancanti nel .env');
            }
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$sid}:{$token}",
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'From' => 'whatsapp:' . $from,
                    'To' => 'whatsapp:' . $telefono,
                    'Body' => $testo,
                ]),
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                throw new \RuntimeException('Twilio: ' . $err);
            }
            return (string) $response;
        }

        // Default: Meta WhatsApp Cloud API
        $token = Config::get('WHATSAPP_META_TOKEN');
        $phoneId = Config::get('WHATSAPP_META_PHONE_ID');
        if (!$token || !$phoneId) {
            throw new \RuntimeException('Credenziali Meta Cloud API mancanti nel .env');
        }
        $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $telefono,
            'type' => 'text',
            'text' => ['body' => $testo],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            throw new \RuntimeException('Meta Cloud API: ' . $err);
        }
        return (string) $response;
    }

    public static function logPerCliente(int $clienteId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM whatsapp_log WHERE cliente_id = ? AND tenant_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$clienteId, Database::tenantId()]);
        return $stmt->fetchAll();
    }
}
