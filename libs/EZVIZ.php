<?php

declare(strict_types=1);

/**
 * Gemeinsame Konstanten und Hilfsfunktionen des Moduls "EZVIZ".
 *
 * Autor: Armin Frohwerk
 */
class EZVIZ
{
    // Datenfluss
    public const DATA_TO_KONTO = '{7E4D686F-4989-4A13-AAF9-F94DECFFE88F}';
    public const DATA_FROM_KONTO = '{5893EB57-6525-408A-BED7-FA6BD08A13F0}';

    // Module
    public const MODUL_KONTO = '{7FD1051B-31F1-4C81-92B4-39D96ED1D346}';
    public const MODUL_KONFIGURATOR = '{D256E72C-D0FF-4536-97B6-FB6929356BA4}';
    public const MODUL_KAMERA = '{C4BCA507-8306-4DC3-B7B0-648BEED24442}';
    public const MODUL_PUSH = '{B4259050-5300-47D8-9DAD-271C4AF28621}';

    // Symcon Client Socket und dessen Datenfluss
    public const CLIENT_SOCKET = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public const DATA_TO_IO = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    public const DATA_FROM_IO = '{018EF6B5-AB94-40C6-AA53-46943E824ACF}';

    // Push (Sofort-Alarme) – Anmeldung wie die Android-App
    public const PUSH_TOKEN = '/v3/push/token';
    public const SERVER_INFO = '/v3/configurations/system/info';
    public const PUSH_REGISTER_JSON = '[{"channel":99}]';
    public const PUSH_EXT_JSON = '{"language":"","protoVer":"2"}';

    // Schnittstelle (EZVIZ-App, inoffiziell)
    public const SERVER_STANDARD = 'apiieu.ezvizlife.com';
    public const LOGIN = '/v3/users/login/v5';
    public const SESSION_ERNEUERN = '/v3/apigateway/login';
    public const CODE_ANFORDERN = '/v3/sms/nologin/checkcode';
    public const GERAETELISTE = '/v3/userdevices/v1/resources/pagelist';
    public const MELDUNGEN = '/v3/unifiedmsg/list';
    public const MELDUNGEN_TYP = '92';
    public const GERAETE = '/v3/devices/';
    public const SCHALTER_ALT = '/api/device/switchStatus';
    public const LISTE_FILTER = 'CLOUD,TIME_PLAN,CONNECTION,SWITCH,STATUS,WIFI,NODISTURB,FEATURE,UPGRADE,FEATURE_INFO';
    public const KENNUNG = 'U3ltY29u'; // "Symcon" (Base64) – Name des Clients in der EZVIZ-App

    // Abschnitte der Geräteliste, die pro Seriennummer aufgeteilt werden
    public const ABSCHNITTE = ['CONNECTION', 'STATUS', 'SWITCH', 'WIFI', 'NODISTURB', 'TIME_PLAN', 'FEATURE', 'UPGRADE', 'FEATURE_INFO'];

    // Eigene Statuscodes
    public const STATUS_HINWEIS = 201;
    public const STATUS_ZUGANG_FEHLT = 202;
    public const STATUS_LOGIN_FEHLER = 203;
    public const STATUS_KEINE_VERBINDUNG = 204;
    public const STATUS_KEINE_SERIENNUMMER = 205;
    public const STATUS_CODE_NOETIG = 206;
    public const STATUS_NICHT_GEFUNDEN = 207;
    public const STATUS_PUSH_FEHLER = 208;

    /**
     * Schalter der Kameras (Typnummer => [Ident-Name, Anzeigename, Icon]).
     * Angelegt werden nur die Schalter, die die jeweilige Kamera meldet.
     */
    public const SCHALTER = [
        21 => ['Schlafmodus', 'Schlafmodus (Privatsphäre)', 'eye-slash'],
        7  => ['Privatsphaere', 'Objektiv abdecken', 'video-slash'],
        3  => ['Statusleuchte', 'Statusleuchte', 'lightbulb'],
        10 => ['Nachtsicht', 'Infrarot-Nachtsicht', 'moon'],
        22 => ['Audio', 'Tonaufnahme', 'microphone'],
        25 => ['Verfolgung', 'Bewegungsverfolgung', 'person-running'],
        1  => ['Alarmton', 'Alarmton', 'volume-high'],
        301 => ['Blinklicht', 'Blinklicht bei Alarm', 'lightbulb'],
        303 => ['Alarmlicht', 'Alarmlicht', 'lightbulb']
    ];

    /**
     * Baut die Anfrage, die eine Kind-Instanz an das Konto schickt.
     */
    public static function Request(string $Befehl, array $Daten = []): string
    {
        return json_encode(['DataID' => self::DATA_TO_KONTO, 'Befehl' => $Befehl] + $Daten);
    }

    /**
     * Wertet die Antwort des Kontos aus.
     * Liefert ['Success' => bool, 'Code' => int, 'Data' => mixed, 'Error' => string]
     */
    public static function Response($Raw): array
    {
        if (!is_string($Raw) || $Raw === '') {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Keine Antwort vom Konto'];
        }
        $Result = json_decode($Raw, true);
        if (!is_array($Result)) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Ungültige Antwort vom Konto'];
        }
        return $Result + ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => ''];
    }

    /**
     * Prüft, ob eine EZVIZ-Antwort erfolgreich war (neue API: meta.code 200, alte API: resultCode 0).
     */
    public static function ApiOk($Daten): bool
    {
        if (!is_array($Daten)) {
            return false;
        }
        if (isset($Daten['meta']['code'])) {
            return (int) $Daten['meta']['code'] === 200;
        }
        if (isset($Daten['resultCode'])) {
            return (string) $Daten['resultCode'] === '0';
        }
        return false;
    }

    /**
     * Fehlercode einer EZVIZ-Antwort.
     */
    public static function ApiCode($Daten): int
    {
        if (is_array($Daten)) {
            if (isset($Daten['meta']['code'])) {
                return (int) $Daten['meta']['code'];
            }
            if (isset($Daten['resultCode'])) {
                return (int) $Daten['resultCode'];
            }
        }
        return 0;
    }

    /**
     * Entschlüsselt ein EZVIZ-Alarmbild (Kennung "hikencodepicture") mit dem Verifizierungscode der Kamera.
     * Unverschlüsselte Bilder werden unverändert zurückgegeben, bei falschem Code kommt null.
     */
    public static function BildEntschluesseln(string $Daten, string $Code): ?string
    {
        $Kopf = 'hikencodepicture';
        $Start = strpos($Daten, $Kopf);
        if ($Start === false) {
            return $Daten;
        }
        if ($Code === '') {
            return null;
        }
        $Daten = substr($Daten, $Start);
        $Hash = md5(md5($Code));
        $Schluessel = str_pad(substr($Code, 0, 16), 16, "\0");
        $IV = '01234567' . str_repeat("\0", 8);

        $Ergebnis = '';
        foreach (explode($Kopf, $Daten) as $Block) {
            if ($Block === '') {
                continue;
            }
            if (strlen($Block) < 32 || substr($Block, 0, 32) !== $Hash) {
                return null;
            }
            $Text = substr($Block, 32);
            $Text = substr($Text, 0, strlen($Text) - (strlen($Text) % 16));
            if ($Text === '') {
                continue;
            }
            $Klar = openssl_decrypt($Text, 'AES-128-CBC', $Schluessel, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $IV);
            if ($Klar === false) {
                return null;
            }
            $Pad = ord(substr($Klar, -1));
            if ($Pad > 0 && $Pad <= 16) {
                $Klar = substr($Klar, 0, -$Pad);
            }
            $Ergebnis .= $Klar;
        }
        return $Ergebnis;
    }
    // ------------------------------------------------------------------
    // Push: Verschlüsselung und Rahmen des EZVIZ-Push-Kanals ("channel 99")
    // ------------------------------------------------------------------

    public static function PushFrame(int $Befehl, string $Daten): string
    {
        $Laenge = strlen($Daten);
        $Kopf = chr($Befehl << 4);
        do {
            $Ziffer = $Laenge & 127;
            $Laenge >>= 7;
            $Kopf .= chr($Ziffer | ($Laenge > 0 ? 128 : 0));
        } while ($Laenge > 0);
        return $Kopf . $Daten;
    }

    public static function PushShareKey(string $Code, string $Serial): string
    {
        $Erst = strtoupper(md5($Code . $Serial));
        $Zweit = strtoupper(md5($Erst . 'www.88075998.com'));
        return strtoupper(md5($Zweit));
    }

    public static function PushMasterKey(string $Zufall4, string $Shared): string
    {
        return strtoupper(bin2hex(substr(hash('sha384', $Zufall4 . $Shared, true), 0, 8)));
    }

    public static function PushSignatur(string $Daten, string $Schluessel): string
    {
        return hash_hmac('sha256', hash('sha256', $Daten, true), $Schluessel, true);
    }

    public static function PushVerschluesseln(string $Schluessel, string $Text): string
    {
        return (string) openssl_encrypt($Text, 'AES-128-CBC', $Schluessel, OPENSSL_RAW_DATA, '01234567' . str_repeat("\0", 8));
    }

    public static function PushEntschluesseln(string $Schluessel, string $Daten): ?string
    {
        if ($Daten === '' || strlen($Daten) % 16) {
            return null;
        }
        $Text = openssl_decrypt($Daten, 'AES-128-CBC', $Schluessel, OPENSSL_RAW_DATA, '01234567' . str_repeat("\0", 8));
        return $Text === false ? null : $Text;
    }

    /**
     * MQTT-Text: 2 Byte Länge + Inhalt
     */
    public static function MqttText(string $Text): string
    {
        return pack('n', strlen($Text)) . $Text;
    }

    /**
     * MQTT-Paket mit festem Kopf (Restlänge als variable Zahl).
     */
    public static function MqttPaket(int $Kopf, string $Rest): string
    {
        $Laenge = strlen($Rest);
        $Zahl = '';
        do {
            $Ziffer = $Laenge & 127;
            $Laenge >>= 7;
            $Zahl .= chr($Ziffer | ($Laenge > 0 ? 128 : 0));
        } while ($Laenge > 0);
        return chr($Kopf) . $Zahl . $Rest;
    }
}
