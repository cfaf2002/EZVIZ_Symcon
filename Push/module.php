<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EZVIZ.php';

/**
 * EZVIZ Push
 * Empfängt Alarme sofort über den Push-Kanal der EZVIZ-App ("channel 99") und
 * stößt beim Konto einen sofortigen Abruf an. Wird vom Konto automatisch angelegt.
 *
 * Ablauf: Anmeldung am Push-Server (LBS) → MQTT-Verbindung über den Client Socket
 * → Abo des eigenen Kanals → bei jedem Alarm EZVIZ_RefreshSoon(Konto).
 *
 * Autor: Armin Frohwerk
 */
class EZVIZPush extends IPSModuleStrict
{
    private const KEEPALIVE = 30;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('KontoID', 0);

        // Zugangsdaten des Push-Kanals (werden wiederverwendet, damit nicht jedes Mal ein neues Gerät angelegt wird)
        $this->RegisterAttributeString('Identitaet', '');
        $this->RegisterAttributeString('DeviceId', '');
        $this->RegisterAttributeString('MasterKey', '');
        $this->RegisterAttributeString('SessionHash', '');
        $this->RegisterAttributeString('SessionKey', '');
        $this->RegisterAttributeString('Serial', '');
        $this->RegisterAttributeString('Puffer', '');
        $this->RegisterAttributeInteger('PaketNr', 1);
        $this->RegisterAttributeInteger('Empfangen', 0);
        $this->RegisterAttributeInteger('Keepalive', self::KEEPALIVE);
        $this->RegisterAttributeBoolean('MqttVerbunden', false);
        $this->RegisterAttributeInteger('Fehlversuche', 0);
        $this->RegisterAttributeInteger('ParentID', 0);

        $this->RegisterTimer('Start', 0, 'EZVIZ_PushStart($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Ping', 0, 'EZVIZ_PushPing($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [EZVIZ::CLIENT_SOCKET]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $Datum = defined('VARIABLE_PRESENTATION_DATE_TIME')
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME]
            : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->RegisterVariableBoolean('Verbunden', 'Push verbunden', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'tower-broadcast'], 1);
        $this->RegisterVariableInteger('LetzterAlarm', 'Letzter Push-Alarm', $Datum + ['ICON' => 'bell'], 2);
        $this->RegisterVariableString('Status', 'Push-Status', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'circle-info'], 3);

        $this->SetTimerInterval('Ping', 0);
        $this->WriteAttributeBoolean('MqttVerbunden', false);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->ParentBeobachten();

        // Nicht hier verbinden (würde das Konto während dessen ApplyChanges aufrufen) – kurz verzögert über den Timer
        $this->SetTimerInterval('Start', 3000);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
                $this->ApplyChanges();
                break;
            case FM_CONNECT:
            case FM_DISCONNECT:
                $this->ParentBeobachten();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID != $this->ReadAttributeInteger('ParentID')) {
                    break;
                }
                if ($Data[0] == IS_ACTIVE) {
                    // Socket ist offen – MQTT-Anmeldung schicken
                    if ($this->ReadAttributeString('SessionKey') !== '') {
                        $this->MqttConnect();
                    }
                } else {
                    $this->Getrennt('Verbindung zum Push-Server getrennt');
                }
                break;
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Status = @$this->GetIDForIdent('Status') ? (string) $this->GetValue('Status') : '';
        $Form['actions'][0]['caption'] = 'Status: ' . ($Status !== '' ? $Status : 'unbekannt');
        return json_encode($Form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (vom Timer bzw. Button aufgerufen)
    // ------------------------------------------------------------------

    /**
     * Baut die Push-Verbindung (neu) auf.
     */
    public function PushStart(): void
    {
        $this->SetTimerInterval('Start', 0);
        $this->SetTimerInterval('Ping', 0);
        $this->WriteAttributeBoolean('MqttVerbunden', false);
        $this->WriteAttributeString('Puffer', '');

        $Konto = $this->ReadPropertyInteger('KontoID');
        if ($Konto <= 0 || !IPS_InstanceExists($Konto)) {
            $this->Fehler('Kein Konto zugeordnet', false);
            return;
        }
        $IO = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($IO <= 0) {
            $this->Fehler('Keine Verbindungsinstanz (Client Socket)', false);
            return;
        }

        $Info = @EZVIZ_GetPushInfo($Konto);
        if (!is_array($Info) || empty($Info['Success'])) {
            $this->Fehler('Konto: ' . (is_array($Info) ? $Info['Error'] : 'keine Antwort'), true);
            return;
        }
        $this->WriteAttributeString('Serial', $Info['Serial']);

        try {
            $Broker = $this->Anmelden($Info);
        } catch (Throwable $e) {
            $this->Fehler('Anmeldung am Push-Server: ' . $e->getMessage(), true);
            return;
        }

        // Client Socket auf den zugeteilten MQTT-Server stellen und (neu) öffnen
        $this->SetzeStatus('Verbinde mit ' . $Broker['Address'] . ':' . $Broker['Port']);
        if (IPS_GetProperty($IO, 'Open')) {
            IPS_SetProperty($IO, 'Open', false);
            IPS_ApplyChanges($IO);
        }
        IPS_SetProperty($IO, 'Host', (string) $Broker['Address']);
        IPS_SetProperty($IO, 'Port', (int) $Broker['Port']);
        IPS_SetProperty($IO, 'Open', true);
        IPS_ApplyChanges($IO);
        // Die MQTT-Anmeldung folgt, sobald der Socket aktiv meldet (MessageSink).
        // Falls die Meldung ausbleibt: nach 30 s erneut versuchen.
        $this->SetTimerInterval('Start', 30000);
    }

    /**
     * Hält die MQTT-Verbindung am Leben und erkennt Abbrüche.
     */
    public function PushPing(): void
    {
        if (!$this->ReadAttributeBoolean('MqttVerbunden')) {
            return;
        }
        $Keepalive = $this->ReadAttributeInteger('Keepalive');
        if (time() - $this->ReadAttributeInteger('Empfangen') > (int) ($Keepalive * 2.5)) {
            $this->Getrennt('Keine Antwort vom Push-Server');
            return;
        }
        $this->Senden(EZVIZ::MqttPaket(0xC0, ''));
    }

    public function ReceiveData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (!is_array($Data) || !isset($Data['Buffer'])) {
            return '';
        }
        $Puffer = $this->ReadAttributeString('Puffer');
        $Puffer = ($Puffer !== '' ? (string) hex2bin($Puffer) : '') . mb_convert_encoding((string) $Data['Buffer'], 'ISO-8859-1', 'UTF-8');
        $this->WriteAttributeInteger('Empfangen', time());

        // Vollständige MQTT-Pakete abarbeiten
        while (strlen($Puffer) >= 2) {
            $Laenge = 0;
            $Faktor = 1;
            $Pos = 1;
            do {
                if ($Pos >= strlen($Puffer)) {
                    break 2; // Länge noch unvollständig
                }
                $Byte = ord($Puffer[$Pos++]);
                $Laenge += ($Byte & 127) * $Faktor;
                $Faktor *= 128;
            } while (($Byte & 128) && $Pos < 5);
            if (strlen($Puffer) < $Pos + $Laenge) {
                break; // Paket noch unvollständig
            }
            $Kopf = ord($Puffer[0]);
            $Inhalt = substr($Puffer, $Pos, $Laenge);
            $Puffer = (string) substr($Puffer, $Pos + $Laenge);
            try {
                $this->MqttPaketVerarbeiten($Kopf, $Inhalt);
            } catch (Throwable $e) {
                $this->SendDebug('Fehler', $e->getMessage(), 0);
            }
        }
        if (strlen($Puffer) > 262144) {
            $Puffer = '';
        }
        $this->WriteAttributeString('Puffer', bin2hex($Puffer));
        return '';
    }

    // ------------------------------------------------------------------
    // Anmeldung am Push-Server (LBS, eigene TCP-Verbindung)
    // ------------------------------------------------------------------

    private function Anmelden(array $Info): array
    {
        $Serial = (string) $Info['Serial'];
        $Session = (string) $Info['Session'];
        $Identitaet = hash('sha256', $Serial);
        if ($this->ReadAttributeString('Identitaet') !== $Identitaet) {
            // Anderes Konto/Gerät: gespeicherte Push-Zugangsdaten verwerfen
            $this->WriteAttributeString('DeviceId', '');
            $this->WriteAttributeString('MasterKey', '');
            $this->WriteAttributeString('SessionHash', '');
            $this->WriteAttributeString('Identitaet', $Identitaet);
        }

        $Sock = @stream_socket_client('tcp://' . $Info['Host'] . ':' . (int) $Info['Port'], $Nr, $Text, 10);
        if ($Sock === false) {
            throw new Exception('Server ' . $Info['Host'] . ' nicht erreichbar (' . $Text . ')');
        }
        stream_set_timeout($Sock, 10);
        try {
            $Device = (string) hex2bin($this->ReadAttributeString('DeviceId'));
            $Master = (string) hex2bin($this->ReadAttributeString('MasterKey'));
            $SessionHash = hash('sha256', $Session);

            if (strlen($Device) === 32 && strlen($Master) === 16 && $this->ReadAttributeString('SessionHash') === $SessionHash) {
                // Gespeicherte Schlüssel erneuern (schnell, ohne neue Anmeldung)
                try {
                    $SessionKey = $this->LbsErneuern($Sock, $Serial, $Device, $Master);
                } catch (Exception $e) {
                    if ($e->getCode() === 10) {
                        $this->WriteAttributeString('MasterKey', '');
                        $this->WriteAttributeString('SessionHash', '');
                    }
                    throw $e;
                }
            } else {
                $SessionKey = $this->LbsAnmelden($Sock, $Serial, $Session, $Device);
            }
            $Broker = $this->LbsServer($Sock, $Serial, $SessionKey);
        } finally {
            fclose($Sock);
        }
        $this->WriteAttributeString('SessionKey', bin2hex($SessionKey));
        $this->SendDebug('Anmeldung', 'OK, MQTT-Server ' . $Broker['Address'] . ':' . $Broker['Port'], 0);
        return $Broker;
    }

    private function LbsAnmelden($Sock, string $Serial, string $Session, string $Device): string
    {
        $Shared = EZVIZ::PushShareKey(md5($Session), $Serial);
        $N1 = random_int(0, 255);
        $N3 = random_int(0, 255);

        // AUTH-I
        $P = "\x01\x03\x00\x02" . chr(strlen($Serial)) . $Serial . chr($N1) . EZVIZ::PushSignatur($Serial . chr($N1), $Shared);
        $A = $this->LbsAustausch($Sock, EZVIZ::PushFrame(1, $P), 2);
        if (strlen($A) < 4 || !in_array(substr($A, 0, 3), ["\x01\x03\x00", "\x01\x00\x00"], true)) {
            throw new Exception('Unerwartete Antwort (AUTH-II)');
        }
        if (ord($A[3]) !== 0) {
            throw new Exception('Anmeldung abgelehnt (Status ' . ord($A[3]) . ')', ord($A[3]));
        }
        if (strlen($A) !== 37) {
            throw new Exception('Antwortlänge falsch (AUTH-II)');
        }
        $N2 = ord($A[4]);
        if (!hash_equals(EZVIZ::PushSignatur($Serial . chr($N1) . chr($N2), $Shared), substr($A, 5))) {
            throw new Exception('Signatur falsch (AUTH-II)');
        }
        $Zufall = chr($N1) . chr($N2) . chr($N3);
        $Sig = EZVIZ::PushSignatur($Serial . chr($N2) . chr($N3), $Shared);

        if (strlen($Device) !== 32) {
            // Neues Push-Gerät anlegen
            $A = $this->LbsAustausch($Sock, EZVIZ::PushFrame(4, "\x01\x01\x00" . chr($N3) . $Sig), 6);
            $this->LbsStatus($A);
            if (strlen($A) !== 119 || ord($A[5]) !== 48 || ord($A[54]) !== 32) {
                throw new Exception('Antwort ungültig (Gerät anlegen)');
            }
            $N4 = ord($A[4]);
            $Master = EZVIZ::PushMasterKey($Zufall . chr($N4), $Shared);
            $Device = (string) EZVIZ::PushEntschluesseln($Master, substr($A, 6, 48));
            $SessionKey = (string) EZVIZ::PushEntschluesseln($Master, substr($A, 55, 32));
            if (strlen($Device) !== 32 || strlen($SessionKey) !== 16) {
                throw new Exception('Schlüssel ungültig (Gerät anlegen)');
            }
            if (!hash_equals(EZVIZ::PushSignatur($Serial . chr($N3) . chr($N4), $Shared), substr($A, 87))) {
                throw new Exception('Signatur falsch (Gerät anlegen)');
            }
        } else {
            // Vorhandenes Push-Gerät
            $A = $this->LbsAustausch($Sock, EZVIZ::PushFrame(3, "\x01\x01\x00" . chr($N3) . chr(32) . $Device . $Sig), 5);
            $this->LbsStatus($A);
            if (strlen($A) !== 70 || ord($A[5]) !== 32) {
                throw new Exception('Antwort ungültig (Gerät anmelden)');
            }
            $N4 = ord($A[4]);
            if (!hash_equals(EZVIZ::PushSignatur($Serial . chr($N3) . chr($N4), $Shared), substr($A, 38))) {
                throw new Exception('Signatur falsch (Gerät anmelden)');
            }
            $Master = EZVIZ::PushMasterKey($Zufall . chr($N4), $Shared);
            $SessionKey = (string) EZVIZ::PushEntschluesseln($Master, substr($A, 6, 32));
            if (strlen($SessionKey) !== 16) {
                throw new Exception('Schlüssel ungültig (Gerät anmelden)');
            }
        }
        $this->WriteAttributeString('DeviceId', bin2hex($Device));
        $this->WriteAttributeString('MasterKey', bin2hex($Master));
        $this->WriteAttributeString('SessionHash', hash('sha256', $Session));
        return $SessionKey;
    }

    private function LbsErneuern($Sock, string $Serial, string $Device, string $Master): string
    {
        $N1 = random_int(0, 255);
        $P = "\x01\x01\x00" . chr(strlen($Serial)) . $Serial . "\x20" . $Device . EZVIZ::PushVerschluesseln($Master, chr($N1));
        $A = $this->LbsAustausch($Sock, EZVIZ::PushFrame(7, $P), 8);
        if (strlen($A) >= 4 && in_array(substr($A, 0, 3), ["\x01\x01\x00", "\x01\x00\x00"], true) && ord($A[3]) !== 0) {
            throw new Exception('Erneuern abgelehnt (Status ' . ord($A[3]) . ')', ord($A[3]));
        }
        if (strlen($A) !== 36) {
            throw new Exception('Antwort ungültig (Erneuern)');
        }
        $Klar = (string) EZVIZ::PushEntschluesseln($Master, substr($A, 4));
        if (strlen($Klar) !== 18 || ord($Klar[0]) !== $N1) {
            throw new Exception('Prüfung fehlgeschlagen (Erneuern)');
        }
        fwrite($Sock, EZVIZ::PushFrame(9, "\x01\x01\x00" . EZVIZ::PushVerschluesseln($Master, $Klar[1])));
        return substr($Klar, 2, 16);
    }

    private function LbsServer($Sock, string $Serial, string $SessionKey): array
    {
        $Anfrage = json_encode(['DevSerial' => $Serial, 'Type' => 'DAS', 'Mode' => 4], JSON_UNESCAPED_SLASHES);
        $A = $this->LbsAustausch($Sock, EZVIZ::PushFrame(10, "\x01\x01\x00" . EZVIZ::PushVerschluesseln($SessionKey, $Anfrage)), 11);
        if (strlen($A) < 20 || !in_array(substr($A, 0, 4), ["\x01\x01\x00\x00", "\x01\x00\x00\x00"], true)) {
            throw new Exception('Antwort ungültig (Serverzuteilung)');
        }
        $Obj = json_decode((string) EZVIZ::PushEntschluesseln($SessionKey, substr($A, 4)), true);
        $Info = is_array($Obj) ? ($Obj['DasInfo'] ?? null) : null;
        if (!is_array($Info) || empty($Info['Address']) || empty($Info['Port'])) {
            throw new Exception('Kein MQTT-Server zugeteilt');
        }
        return $Info;
    }

    private function LbsStatus(string $A): void
    {
        if (strlen($A) < 4 || !in_array(substr($A, 0, 3), ["\x01\x01\x00", "\x01\x00\x00"], true)) {
            throw new Exception('Unerwartete Antwort');
        }
        if (ord($A[3]) !== 0) {
            throw new Exception('Abgelehnt (Status ' . ord($A[3]) . ')', ord($A[3]));
        }
    }

    private function LbsAustausch($Sock, string $Rahmen, int $Erwartet): string
    {
        fwrite($Sock, $Rahmen);
        $Erst = $this->Lesen($Sock, 1);
        $Laenge = 0;
        for ($i = 0; $i < 4; $i++) {
            $Ziffer = ord($this->Lesen($Sock, 1));
            $Laenge |= ($Ziffer & 127) << (7 * $i);
            if (!($Ziffer & 128)) {
                break;
            }
        }
        if ($Laenge > 65536) {
            throw new Exception('Antwort zu groß');
        }
        $Daten = $Laenge > 0 ? $this->Lesen($Sock, $Laenge) : '';
        $Befehl = ord($Erst) >> 4;
        if ($Befehl !== $Erwartet) {
            throw new Exception('Unerwarteter Befehl ' . $Befehl . ' (erwartet ' . $Erwartet . ')');
        }
        return $Daten;
    }

    private function Lesen($Sock, int $Anzahl): string
    {
        $Daten = '';
        $Ende = microtime(true) + 10;
        while (strlen($Daten) < $Anzahl) {
            $Teil = fread($Sock, $Anzahl - strlen($Daten));
            if ($Teil === false || ($Teil === '' && (feof($Sock) || microtime(true) > $Ende))) {
                throw new Exception('Verbindung beendet oder Zeitüberschreitung');
            }
            $Daten .= $Teil;
        }
        return $Daten;
    }

    // ------------------------------------------------------------------
    // MQTT über den Client Socket
    // ------------------------------------------------------------------

    private function MqttConnect(): void
    {
        $Serial = $this->ReadAttributeString('Serial');
        $Device = (string) hex2bin($this->ReadAttributeString('DeviceId'));
        $SessionKey = (string) hex2bin($this->ReadAttributeString('SessionKey'));
        if ($Serial === '' || strlen($Device) !== 32 || strlen($SessionKey) !== 16) {
            return;
        }
        $Will = json_encode([
            'DevSerial' => '', 'SubSerial' => $Serial, 'FirmwareVersion' => '', 'DevType' => '', 'DevTypeDisplay' => '',
            'MAC' => '', 'Status' => 0, 'NickName' => '', 'FirmwareIdentificationCode' => '', 'dev_oeminfo' => 0,
            'LbsDomain' => '', 'RegMode' => 0, 'SDKMainVersion' => 'V2.4.0', 'SDKVersion' => ['1000' => 'V2.4.0', '0' => '']
        ], JSON_UNESCAPED_SLASHES);
        $WillTopic = str_pad('/Basic/pu2cenplt/' . $Serial . '/firstconnect', 128, "\0");

        // Flags: Benutzername, Will-Retain, Will-QoS 1, Will, Clean Session
        $Variabel = EZVIZ::MqttText('MQTT') . chr(4) . chr(0x80 | 0x20 | 0x08 | 0x04 | 0x02) . pack('n', self::KEEPALIVE);
        $Nutzdaten = EZVIZ::MqttText($Device) . EZVIZ::MqttText($WillTopic)
            . EZVIZ::MqttText(EZVIZ::PushVerschluesseln($SessionKey, $Will)) . EZVIZ::MqttText($Serial);
        $this->WriteAttributeInteger('Empfangen', time());
        $this->SendDebug('MQTT', 'CONNECT', 0);
        $this->Senden(EZVIZ::MqttPaket(0x10, $Variabel . $Nutzdaten));
    }

    private function MqttPaketVerarbeiten(int $Kopf, string $Inhalt): void
    {
        switch ($Kopf >> 4) {
            case 2: // CONNACK
                $Code = strlen($Inhalt) >= 2 ? ord($Inhalt[1]) : -1;
                if ($Code !== 0) {
                    $this->Fehler('MQTT-Anmeldung abgelehnt (Code ' . $Code . ')', true);
                    return;
                }
                $Serial = $this->ReadAttributeString('Serial');
                $Nr = $this->NaechstePaketNr();
                $this->Senden(EZVIZ::MqttPaket(0x82, pack('n', $Nr) . EZVIZ::MqttText('/' . $Serial . '/#') . chr(1)));
                break;

            case 9: // SUBACK
                $Code = strlen($Inhalt) >= 3 ? ord($Inhalt[2]) : 0x80;
                if ($Code === 0x80) {
                    $this->Fehler('Abo des Push-Kanals abgelehnt', true);
                    return;
                }
                $this->WriteAttributeBoolean('MqttVerbunden', true);
                $this->WriteAttributeInteger('Fehlversuche', 0);
                $this->SetTimerInterval('Start', 0);
                $this->SetTimerInterval('Ping', $this->ReadAttributeInteger('Keepalive') * 1000);
                $this->Setzen('Verbunden', true);
                $this->SetzeStatus('Verbunden – Alarme kommen sofort');
                if ($this->GetStatus() != IS_ACTIVE) {
                    $this->SetStatus(IS_ACTIVE);
                }
                break;

            case 3: // PUBLISH
                $QoS = ($Kopf >> 1) & 3;
                $TopicLaenge = unpack('n', substr($Inhalt, 0, 2))[1];
                $Topic = substr($Inhalt, 2, $TopicLaenge);
                $Pos = 2 + $TopicLaenge;
                if ($QoS > 0) {
                    $Id = substr($Inhalt, $Pos, 2);
                    $Pos += 2;
                    $this->Senden(EZVIZ::MqttPaket(0x40, $Id)); // PUBACK
                }
                $this->Nachricht($Topic, substr($Inhalt, $Pos));
                break;

            case 13: // PINGRESP
                break;
        }
    }

    private function Nachricht(string $Topic, string $Daten): void
    {
        if (!preg_match('#^/([^/]+)/([0-9]+)/([0-9]+)#', $Topic, $T) || strlen($Daten) > 65536) {
            return;
        }
        $Klar = EZVIZ::PushEntschluesseln((string) hex2bin($this->ReadAttributeString('SessionKey')), $Daten);
        if ($Klar === null || strlen($Klar) < 2) {
            return;
        }
        $Groesse = unpack('n', substr($Klar, 0, 2))[1];
        $Meta = json_decode(rtrim(substr($Klar, 2, $Groesse), "\0"), true);
        $Inhalt = (string) substr($Klar, 2 + $Groesse);
        $Bereich = (int) $T[2];
        $Befehl = (int) $T[3];

        if ($Bereich === 1000 && $Befehl === 1) {
            // Vom Server vorgegebenes Keepalive
            $Obj = json_decode(rtrim($Inhalt, "\0"), true);
            $Intervall = (int) ($Obj['KeepAlive']['Interval'] ?? 0);
            if ($Intervall >= 10 && $Intervall <= 600) {
                $this->WriteAttributeInteger('Keepalive', $Intervall);
                $this->SetTimerInterval('Ping', $Intervall * 1000);
            }
        } elseif ($Bereich === 9000 && $Befehl === 1) {
            $Obj = json_decode(rtrim($Inhalt, "\0"), true);
            if (!is_array($Obj)) {
                return;
            }
            $Ext = explode(',', (string) ($Obj['ext'] ?? ''));
            $Serial = (string) ($Ext[2] ?? '');
            $this->SendDebug('Alarm', 'Kamera ' . $Serial . ', Typ ' . ($Ext[4] ?? '?'), 0);
            $this->SetValue('LetzterAlarm', time());
            // Konto sofort abrufen lassen – die Kameras holen sich die neuen Daten dann selbst
            $Konto = $this->ReadPropertyInteger('KontoID');
            if ($Konto > 0 && IPS_InstanceExists($Konto)) {
                @EZVIZ_RefreshSoon($Konto);
            }
        } elseif ($Bereich === 9000 && $Befehl >= 0x6000 && $Befehl <= 0x6FFF && is_array($Meta)) {
            // Bestätigung, die die App für diese Nachrichten schickt
            $Antwort = json_encode(['CmdVer' => 'v2.4.0 build 20250310', 'Seq' => (int) ($Meta['Seq'] ?? 0), 'MsgType' => 2]);
            $Paket = EZVIZ::PushVerschluesseln((string) hex2bin($this->ReadAttributeString('SessionKey')), pack('n', strlen($Antwort)) . $Antwort . '{}');
            $this->Senden(EZVIZ::MqttPaket(0x30, EZVIZ::MqttText('/' . $Bereich . '/' . ($Befehl + 1)) . $Paket));
        }
    }

    private function NaechstePaketNr(): int
    {
        $Nr = $this->ReadAttributeInteger('PaketNr');
        $this->WriteAttributeInteger('PaketNr', $Nr >= 65535 ? 1 : $Nr + 1);
        return $Nr;
    }

    private function Senden(string $Daten): void
    {
        if (!$this->HasActiveParent()) {
            return;
        }
        @$this->SendDataToParent(json_encode([
            'DataID' => EZVIZ::DATA_TO_IO,
            'Buffer' => mb_convert_encoding($Daten, 'UTF-8', 'ISO-8859-1')
        ]));
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function Getrennt(string $Grund): void
    {
        $War = $this->ReadAttributeBoolean('MqttVerbunden');
        $this->WriteAttributeBoolean('MqttVerbunden', false);
        $this->SetTimerInterval('Ping', 0);
        $this->Setzen('Verbunden', false);
        if ($War) {
            $this->SendDebug('Getrennt', $Grund, 0);
        }
        $this->SetzeStatus($Grund . ' – neuer Versuch gleich');
        $this->SetTimerInterval('Start', $this->Wartezeit() * 1000);
    }

    private function Fehler(string $Text, bool $Wiederholen): void
    {
        $this->SendDebug('Fehler', $Text, 0);
        $this->Setzen('Verbunden', false);
        $Nr = $this->ReadAttributeInteger('Fehlversuche') + 1;
        $this->WriteAttributeInteger('Fehlversuche', $Nr);
        if ($Nr === 1 || $Nr % 10 === 0) {
            $this->LogMessage('EZVIZ Push: ' . $Text, KL_WARNING);
        }
        $this->SetStatus(EZVIZ::STATUS_PUSH_FEHLER);
        if ($Wiederholen) {
            $Sekunden = $this->Wartezeit();
            $this->SetzeStatus($Text . ' – neuer Versuch in ' . ($Sekunden >= 60 ? round($Sekunden / 60) . ' min' : $Sekunden . ' s'));
            $this->SetTimerInterval('Start', $Sekunden * 1000);
        } else {
            $this->SetzeStatus($Text);
        }
    }

    /**
     * Wartezeit bis zum nächsten Versuch: 30 s, steigend bis 15 Minuten.
     */
    private function Wartezeit(): int
    {
        $Nr = max(1, $this->ReadAttributeInteger('Fehlversuche'));
        return (int) min(900, 30 * pow(2, min($Nr - 1, 5)));
    }

    private function SetzeStatus(string $Text): void
    {
        if (@$this->GetIDForIdent('Status')) {
            $this->SetValue('Status', date('H:i:s') . ' ' . $Text);
        }
    }

    private function Setzen(string $Ident, mixed $Wert): void
    {
        $ID = @$this->GetIDForIdent($Ident);
        if ($ID && GetValue($ID) !== $Wert) {
            $this->SetValue($Ident, $Wert);
        }
    }

    private function ParentBeobachten(): void
    {
        $Alt = $this->ReadAttributeInteger('ParentID');
        $Neu = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($Alt != $Neu) {
            if ($Alt > 0) {
                @$this->UnregisterMessage($Alt, IM_CHANGESTATUS);
            }
            if ($Neu > 0) {
                $this->RegisterMessage($Neu, IM_CHANGESTATUS);
            }
            $this->WriteAttributeInteger('ParentID', $Neu);
        }
    }
}
