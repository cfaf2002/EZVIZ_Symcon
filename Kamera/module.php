<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EZVIZ.php';

/**
 * EZVIZ Kamera
 * Eine EZVIZ-Kamera als Instanz: Status und Schalter über die EZVIZ-Cloud,
 * Livestream lokal per RTSP, letzter Alarm samt Bild.
 *
 * Autor: Armin Frohwerk
 */
class EZVIZKamera extends IPSModuleStrict
{
    private const RICHTUNGEN = [
        1 => ['LEFT', 'Links', 'arrow-left'],
        2 => ['RIGHT', 'Rechts', 'arrow-right'],
        3 => ['UP', 'Hoch', 'arrow-up'],
        4 => ['DOWN', 'Runter', 'arrow-down']
    ];

    // Stream-Auswahl: 0 Unterstream, 1 Hauptstream, 2 Standard, 3 eigener Pfad
    private const STREAMS = [
        0 => '/h264/ch1/sub/av_stream',
        1 => '/h264/ch1/main/av_stream',
        2 => '/H.264',
        3 => ''
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Serial', '');
        $this->RegisterPropertyString('Verifizierungscode', '');
        $this->RegisterPropertyBoolean('Livestream', true);
        $this->RegisterPropertyString('Benutzer', 'admin');
        $this->RegisterPropertyString('IP', '');
        $this->RegisterPropertyInteger('Port', 554);
        $this->RegisterPropertyInteger('Stream', 0);
        $this->RegisterPropertyString('Pfad', '/H.264');
        $this->RegisterPropertyBoolean('Standbild', true);
        $this->RegisterPropertyInteger('StandbildQuelle', 0);
        $this->RegisterPropertyInteger('StandbildIntervall', 30);
        $this->RegisterPropertyBoolean('StandbildAkku', false);
        $this->RegisterPropertyString('FFmpeg', '');
        $this->RegisterPropertyBoolean('Kachel', true);
        $this->RegisterPropertyBoolean('Alarmbild', true);
        $this->RegisterPropertyInteger('Bewegungsdauer', 60);
        $this->RegisterPropertyInteger('Schwenkdauer', 500);
        $this->RegisterPropertyInteger('AkkuGrenze', 20);
        $this->RegisterPropertyBoolean('AkkuMeldung', true);
        $this->RegisterPropertyInteger('VisuID', 0);

        $this->RegisterAttributeString('Daten', '');
        $this->RegisterAttributeString('AlarmId', '');
        $this->RegisterAttributeString('LokaleIP', '');
        $this->RegisterAttributeInteger('ParentID', 0);
        $this->RegisterAttributeInteger('AbrufID', 0);
        $this->RegisterAttributeInteger('StandbildZeit', 0);
        $this->RegisterAttributeInteger('LokalPause', 0);
        $this->RegisterAttributeBoolean('AkkuGemeldet', false);
        $this->RegisterAttributeString('StandbildFehler', '');
        $this->RegisterAttributeString('StandbildWeg', '');
        $this->RegisterAttributeInteger('LetzterLauf', 0);
        $this->RegisterAttributeString('FFmpegPfad', '');

        // Timer rufen öffentliche Funktionen auf (bewährtes Muster, unabhängig von Variablen-Idents)
        $this->RegisterTimer('BewegungAus', 0, 'EZVIZ_TimerMotionReset($_IPS[\'TARGET\']);');
        // Eigener Aktionsname, da "Standbild" schon das Medienobjekt heißt
        $this->RegisterTimer('Standbild', 0, 'EZVIZ_TimerSnapshot($_IPS[\'TARGET\']);');
        // Einmaliges, sofortiges Standbild (nach Übernehmen, Alarm, Schwenken) – getrennt vom festen Intervall
        $this->RegisterTimer('StandbildSofort', 0, 'EZVIZ_TimerSnapshotOnce($_IPS[\'TARGET\']);');
    }

    /**
     * Übergeordnete Instanz: EZVIZ Konto
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'connect',
            'moduleIDs' => [EZVIZ::MODUL_KONTO]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $Serial = trim($this->ReadPropertyString('Serial'));
        $this->SetReceiveDataFilter($Serial !== '' ? '.*"Serial":"' . preg_quote($Serial, '/') . '".*' : '.*"Serial":"-".*');

        $this->GrundvariablenAnlegen();

        if (!$this->ReadPropertyBoolean('Alarmbild')) {
            $this->MedienLoeschen('Alarmbild');
        }
        if (!$this->ReadPropertyBoolean('Livestream')) {
            $this->MedienLoeschen('Livestream');
        }
        if (!$this->ReadPropertyBoolean('Standbild')) {
            $this->MedienLoeschen('Standbild');
        }
        $this->SetVisualizationType($this->ReadPropertyBoolean('Kachel') ? 1 : 0);
        $this->SetTimerInterval('Standbild', 0);
        $this->SetTimerInterval('StandbildSofort', 0);
        $this->WriteAttributeInteger('LokalPause', 0);
        $this->WriteAttributeString('FFmpegPfad', '');

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->ParentBeobachten();

        if ($Serial === '') {
            $this->SetStatus(EZVIZ::STATUS_KEINE_SERIENNUMMER);
            return;
        }

        // Zuletzt bekannte Daten sofort anwenden (z. B. geänderter RTSP-Pfad)
        $Alt = json_decode($this->ReadAttributeString('Daten'), true);
        if (is_array($Alt)) {
            $this->Verarbeiten($Alt, false);
        }

        // Standbild: gleich ein frisches holen, danach im festen Intervall
        if ($this->ReadPropertyBoolean('Standbild')) {
            $this->SetTimerInterval('StandbildSofort', 2000);
        }
        $this->StandbildTimer();

        // Aktuelle Daten beim Konto nachfragen
        $this->Start();
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
                $this->Start();
                break;
            case IM_CHANGESTATUS:
                if ($SenderID == $this->ReadAttributeInteger('ParentID')) {
                    $this->AbrufBeobachten();
                    $this->Start();
                }
                break;
            case VM_UPDATE:
                // Das Konto hat neue Daten geholt – jetzt selbst abholen
                if ($SenderID == $this->ReadAttributeInteger('AbrufID')) {
                    $this->Start();
                }
                break;
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (!is_array($Data) || ($Data['Serial'] ?? '') !== trim($this->ReadPropertyString('Serial'))) {
            return '';
        }
        if (($Data['Typ'] ?? '') === 'Status' && is_array($Data['Daten'] ?? null)) {
            $this->Verarbeiten($Data['Daten'], true);
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (strpos($Ident, 'Schalter') === 0) {
            $this->SetSwitch((int) substr($Ident, 8), (bool) $Value);
            return;
        }
        switch ($Ident) {
            case 'Bewegungserkennung':
                $this->SetMotionDetection((bool) $Value);
                break;
            case 'Schwenken':
                if (isset(self::RICHTUNGEN[(int) $Value])) {
                    $this->Move(strtolower(self::RICHTUNGEN[(int) $Value][0]));
                }
                break;
            case 'Aktualisieren':
                $this->Update();
                if ($this->ReadPropertyBoolean('Standbild')) {
                    $this->SetTimerInterval('StandbildSofort', 1000);
                }
                break;
            case 'StandbildTimer':
            case 'Standbild':
                $this->WriteAttributeInteger('LetzterLauf', time());
                $this->StandbildTimer();
                $this->UpdateSnapshot();
                break;
            case 'StandbildSofort':
                $this->SetTimerInterval('StandbildSofort', 0);
                $this->UpdateSnapshot();
                break;
            case 'Kachel':
                $this->KachelAktion((string) $Value);
                break;
            case 'BewegungAus':
                $this->SetTimerInterval('BewegungAus', 0);
                $this->Setzen('Bewegung', false);
                $this->KachelSenden();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $Daten = json_decode($this->ReadAttributeString('Daten'), true);
        $Info = 'Noch keine Daten vom Konto';
        if (is_array($Daten)) {
            $I = $Daten['deviceInfos'] ?? [];
            $Info = trim((string) ($I['name'] ?? '') . ' – ' . (string) ($I['deviceType'] ?? '') . ' – Firmware ' . (string) ($I['version'] ?? '?'));
            $IP = $this->IPAdresse();
            if ($IP !== '') {
                $Info .= ' – IP ' . $IP;
            }
        }
        $Url = $this->StreamUrl(true);

        foreach ($Form['actions'] as &$Element) {
            if (($Element['name'] ?? '') === 'Geraeteinfo') {
                $Element['caption'] = $Info;
            }
            if (($Element['name'] ?? '') === 'StandbildInfo') {
                $Element['caption'] = $this->StandbildInfoText();
            }
            if (($Element['name'] ?? '') === 'StreamInfo') {
                $Element['caption'] = $Url !== '' ? 'RTSP: ' . $Url : 'RTSP: IP-Adresse noch unbekannt';
            }
        }
        return json_encode($Form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (EZVIZ_...)
    // ------------------------------------------------------------------

    /**
     * Lässt das Konto alle Geräte sofort neu abfragen.
     */
    public function Update(): bool
    {
        if (!$this->HasActiveParent()) {
            $this->SetStatus(EZVIZ::STATUS_KEINE_VERBINDUNG);
            return false;
        }
        return EZVIZ::Response(@$this->SendDataToParent(EZVIZ::Request('Aktualisieren')))['Success'];
    }

    /**
     * Bewegungserkennung (Alarm-Benachrichtigung) ein- oder ausschalten.
     */
    public function SetMotionDetection(bool $Aktiv): bool
    {
        $Result = $this->Senden('PUT', EZVIZ::GERAETE . $this->Serial() . '/1/changeDefenceStatusReq', [
            'type'   => 'Global',
            'status' => $Aktiv ? 1 : 0,
            'actor'  => 'V'
        ]);
        if (!$Result['Success']) {
            $this->Fehler('Bewegungserkennung', $Result);
            return false;
        }
        $this->Setzen('Bewegungserkennung', $Aktiv);
        return true;
    }

    /**
     * Einen Kamera-Schalter setzen, z. B. 21 = Schlafmodus, 3 = Statusleuchte, 10 = Nachtsicht.
     */
    public function SetSwitch(int $Typ, bool $Aktiv): bool
    {
        $Serial = $this->Serial();
        $Result = $this->Senden('PUT', EZVIZ::GERAETE . $Serial . '/0/' . ($Aktiv ? 1 : 0) . '/' . $Typ . '/switchStatus');
        if (!$Result['Success']) {
            // Ältere Firmware
            $Result = $this->Senden('POST', EZVIZ::SCHALTER_ALT, [
                'serial'  => $Serial,
                'enable'  => $Aktiv ? '1' : '0',
                'type'    => (string) $Typ,
                'channel' => '0'
            ]);
        }
        if (!$Result['Success']) {
            $this->Fehler('Schalter ' . $Typ, $Result);
            return false;
        }
        $this->Setzen('Schalter' . $Typ, $Aktiv);
        return true;
    }

    /**
     * Kamera schwenken: 'left', 'right', 'up' oder 'down'.
     */
    public function Move(string $Richtung): bool
    {
        $Richtung = strtoupper($Richtung);
        if (!in_array($Richtung, ['LEFT', 'RIGHT', 'UP', 'DOWN'], true)) {
            return false;
        }
        $Serial = $this->Serial();
        $Pfad = EZVIZ::GERAETE . $Serial . '/ptzControl';
        $Form = ['command' => $Richtung, 'channelNo' => 1, 'speed' => 5, 'serial' => $Serial];

        $Result = $this->Senden('PUT', $Pfad, $Form + ['action' => 'START', 'uuid' => self::Uuid()]);
        usleep(max(100, min(5000, $this->ReadPropertyInteger('Schwenkdauer'))) * 1000);
        $this->Senden('PUT', $Pfad, $Form + ['action' => 'STOP', 'uuid' => self::Uuid()]);

        if (!$Result['Success']) {
            $this->Fehler('Schwenken', $Result);
            return false;
        }
        return true;
    }

    /**
     * Holt sofort ein neues Standbild (lokal per FFmpeg oder über die Cloud).
     */
    public function UpdateSnapshot(): bool
    {
        if (trim($this->ReadPropertyString('Serial')) === '') {
            $this->WriteAttributeString('StandbildFehler', date('H:i:s') . ' Keine Seriennummer');
            return false;
        }
        if ($this->Schlaeft()) {
            $this->WriteAttributeString('StandbildFehler', date('H:i:s') . ' Kamera im Schlafmodus – kein Bild möglich');
            $this->SendDebug('Standbild', 'Kamera im Schlafmodus – übersprungen', 0);
            return false;
        }
        try {
            $Ok = $this->StandbildHolen();
        } catch (Throwable $e) {
            $Text = 'Fehler im Modul: ' . $e->getMessage() . ' (Zeile ' . $e->getLine() . ')';
            $this->WriteAttributeString('StandbildFehler', date('H:i:s') . ' ' . $Text);
            $this->SendDebug('Standbild', $Text, 0);
            $Ok = false;
        }
        $this->StandbildInfoZeigen();
        return $Ok;
    }

    /**
     * Zustand der eigenen Timer als Text (Fehlersuche).
     */
    public function GetTimerInfo(): string
    {
        $Zeilen = [];
        foreach (IPS_GetTimerList() as $TimerID) {
            $T = IPS_GetTimer($TimerID);
            if (($T['InstanceID'] ?? 0) != $this->InstanceID) {
                continue;
            }
            $Zeit = function ($Wert) {
                return (is_numeric($Wert) && $Wert > 0) ? date('H:i:s', (int) $Wert) : '–';
            };
            $Zeilen[] = $T['Name'] . ': alle ' . round(($T['Interval'] ?? 0) / 1000, 1) . ' s, zuletzt ' . $Zeit($T['LastRun'] ?? 0)
                . ', nächster ' . $Zeit($T['NextRun'] ?? 0) . ', läuft gerade ' . (!empty($T['Running']) ? 'ja' : 'nein');
        }
        // Laufende Skripte – hängen hier viele lange, ist der Skript-Pool von Symcon voll und Timer warten
        if (function_exists('IPS_GetScriptThreadList') && function_exists('IPS_GetScriptThread')) {
            $Laufend = [];
            foreach (IPS_GetScriptThreadList() as $ThreadID) {
                $T = @IPS_GetScriptThread($ThreadID);
                if (!is_array($T) || empty($T['StartTime'])) {
                    continue;
                }
                $Laufend[] = '  seit ' . date('H:i:s', (int) $T['StartTime']) . ': ' . trim((string) ($T['FilePath'] ?? '') . ' ' . (string) ($T['Sender'] ?? ''))
                    . (!empty($T['ScriptID']) ? ' (Skript #' . $T['ScriptID'] . ')' : '');
            }
            $Zeilen[] = 'Laufende Skripte: ' . count($Laufend) . (count($Laufend) ? "\n" . implode("\n", array_slice($Laufend, 0, 15)) : '');
        }
        return 'Jetzt ' . date('H:i:s') . "\n" . (count($Zeilen) ? implode("\n", $Zeilen) : 'Keine Timer gefunden');
    }

    /**
     * Vom Timer aufgerufen: regelmäßiges Standbild.
     */
    public function TimerSnapshot(): void
    {
        $this->RequestAction('StandbildTimer', true);
    }

    /**
     * Vom Timer aufgerufen: einmaliges Standbild (nach Übernehmen, Alarm, Schwenken).
     */
    public function TimerSnapshotOnce(): void
    {
        $this->RequestAction('StandbildSofort', true);
    }

    /**
     * Vom Timer aufgerufen: „Bewegung erkannt“ zurücksetzen.
     */
    public function TimerMotionReset(): void
    {
        $this->RequestAction('BewegungAus', true);
    }

    /**
     * Ergebnis des letzten Standbild-Versuchs als Text.
     */
    public function GetSnapshotStatus(): string
    {
        $Fehler = $this->ReadAttributeString('StandbildFehler');
        if ($Fehler !== '') {
            return 'Letzter Versuch um ' . $Fehler;
        }
        $Zeit = $this->ReadAttributeInteger('StandbildZeit');
        if ($Zeit <= 0) {
            return 'Noch kein Standbild.';
        }
        $Weg = $this->ReadAttributeString('StandbildWeg');
        return 'Standbild aktualisiert um ' . date('H:i:s', $Zeit) . ($Weg !== '' ? ' (' . $Weg . ')' : '') . '.';
    }

    private function StandbildInfoText(): string
    {
        $Zeit = $this->ReadAttributeInteger('StandbildZeit');
        $Fehler = $this->ReadAttributeString('StandbildFehler');
        $Weg = $this->ReadAttributeString('StandbildWeg');
        [$Ms, $Grund] = $this->AutoIntervall();
        $Lauf = $this->ReadAttributeInteger('LetzterLauf');
        $Auto = $Ms > 0
            ? 'Automatisch alle ' . ($Ms / 1000) . ' s (Timer ' . ($this->GetTimerInterval('Standbild') > 0 ? 'läuft' : 'steht!') . ', letzter Lauf ' . ($Lauf > 0 ? date('H:i:s', $Lauf) : 'noch nie') . ')'
            : 'Automatisch aus: ' . $Grund;
        return 'Standbild: ' . ($Zeit > 0 ? 'zuletzt ' . date('d.m. H:i:s', $Zeit) . ($Weg !== '' ? ' (' . $Weg . ')' : '') : 'noch keins')
            . ($Fehler !== '' ? ' – letzter Versuch ' . $Fehler : '') . ' · ' . $Auto;
    }

    /**
     * Aktualisiert die Standbild-Zeile in der geöffneten Instanz-Konfiguration.
     */
    private function StandbildInfoZeigen(): void
    {
        @$this->UpdateFormField('StandbildInfo', 'caption', $this->StandbildInfoText());
    }

    private function StandbildHolen(): bool
    {
        $Quelle = $this->ReadPropertyInteger('StandbildQuelle');
        $Bild = null;
        $Fehler = [];
        $Weg = '';
        // Akku-Kameras schlafen meist – lokal ist dann niemand erreichbar, also gleich die Cloud
        $Lokal = $Quelle === 2 || ($Quelle === 0 && !$this->HatAkku() && $this->ReadAttributeInteger('LokalPause') < time());
        if ($Lokal) {
            $Versuch = microtime(true);
            $Bild = $this->StandbildLokal($Fehler);
            if ($Bild === null && microtime(true) - $Versuch < 5) {
                // zweiter Versuch – die Kamera lässt oft nur eine Verbindung gleichzeitig zu
                usleep(500000);
                $Bild = $this->StandbildLokal($Fehler);
            }
            if ($Bild === null && $Quelle === 0) {
                // 3 Minuten lang direkt die Cloud nehmen, dann wieder lokal versuchen
                $this->WriteAttributeInteger('LokalPause', time() + 180);
            }
            $Weg = 'lokal';
        }
        if ($Bild === null && $Quelle !== 2) {
            $Bild = $this->StandbildCloud($Fehler);
            $Weg = 'Cloud';
        }
        if ($Bild === null) {
            $Text = 'Kein neues Bild: ' . implode(' / ', array_unique($Fehler));
            $this->WriteAttributeString('StandbildFehler', date('H:i:s') . ' ' . $Text);
            $this->SendDebug('Standbild', $Text, 0);
            $this->KachelSenden();
            return false;
        }
        $Bild = $this->BildVerkleinern($Bild);
        $ID = $this->Medium('Standbild', MEDIATYPE_IMAGE, 'Standbild', 39);
        IPS_SetMediaFile($ID, 'media/EZVIZ_' . $this->InstanceID . '_Standbild.jpg', false);
        IPS_SetMediaContent($ID, base64_encode($Bild));
        $this->WriteAttributeInteger('StandbildZeit', time());
        $this->WriteAttributeString('StandbildFehler', '');
        $this->WriteAttributeString('StandbildWeg', $Weg);
        $this->SendDebug('Standbild', 'aktualisiert (' . $Weg . ', ' . round(strlen($Bild) / 1024) . ' KB)', 0);
        $this->KachelSenden(true);
        return true;
    }

    /**
     * Verkleinert große Bilder (z. B. 2K-Fotos aus der Cloud) auf höchstens 1280 Pixel Breite,
     * damit die Kachel sie schnell bekommt.
     */
    private function BildVerkleinern(string $Bild): string
    {
        if (!function_exists('imagecreatefromstring') || strlen($Bild) < 150000) {
            return $Bild;
        }
        $Quelle = @imagecreatefromstring($Bild);
        if ($Quelle === false) {
            return $Bild;
        }
        $B = imagesx($Quelle);
        $H = imagesy($Quelle);
        $Ziel = $Quelle;
        if ($B > 1280) {
            $NeuH = (int) round($H * 1280 / $B);
            $Ziel = imagecreatetruecolor(1280, $NeuH);
            imagecopyresampled($Ziel, $Quelle, 0, 0, 0, 0, 1280, $NeuH, $B, $H);
        }
        ob_start();
        imagejpeg($Ziel, null, 80);
        $Neu = (string) ob_get_clean();
        return ($Neu !== '' && strlen($Neu) < strlen($Bild)) ? $Neu : $Bild;
    }

    private function HatAkku(): bool
    {
        return (bool) @$this->GetIDForIdent('Akku');
    }

    public function GetVisualizationTile(): string
    {
        $HTML = file_get_contents(__DIR__ . '/module.html');
        $Daten = json_encode($this->KachelDaten(true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $HTML . '<script>handleMessage(' . json_encode($Daten, JSON_HEX_TAG) . ');</script>';
    }

    /**
     * RTSP-Adresse des Livestreams (mit Zugangsdaten).
     */
    public function GetStreamUrl(): string
    {
        return $this->StreamUrl(false);
    }

    /**
     * Rohdaten der Kamera aus der Cloud (zur Fehlersuche).
     */
    public function GetData(): array
    {
        $Daten = json_decode($this->ReadAttributeString('Daten'), true);
        return is_array($Daten) ? $Daten : [];
    }

    // ------------------------------------------------------------------
    // Daten verarbeiten
    // ------------------------------------------------------------------

    private function Start(): void
    {
        if (trim($this->ReadPropertyString('Serial')) === '') {
            $this->SetStatus(EZVIZ::STATUS_KEINE_SERIENNUMMER);
            return;
        }
        if (!$this->HasActiveParent()) {
            $this->SetStatus(EZVIZ::STATUS_KEINE_VERBINDUNG);
            return;
        }
        $this->AbrufBeobachten();
        $Result = EZVIZ::Response(@$this->SendDataToParent(EZVIZ::Request('Status', ['Serial' => $this->Serial()])));
        if ($Result['Success'] && is_array($Result['Data'])) {
            $this->Verarbeiten($Result['Data'], true);
        } elseif ($Result['Code'] == 404 && $Result['Error'] === 'Gerät nicht im Konto') {
            $this->SetStatus(EZVIZ::STATUS_NICHT_GEFUNDEN);
        } elseif ($this->GetStatus() != IS_ACTIVE) {
            // Konto hat noch keine Daten – sie kommen mit dem nächsten Abruf
            $this->SetStatus(IS_ACTIVE);
        }
    }

    private function Verarbeiten(array $D, bool $Neu): void
    {
        if ($Neu) {
            $this->WriteAttributeString('Daten', json_encode($D));
        }
        $Info = $D['deviceInfos'] ?? [];
        $Status = $D['STATUS'] ?? [];
        $Optionen = is_array($Status['optionals'] ?? null) ? $Status['optionals'] : [];
        $Support = is_array($Info['supportExt'] ?? null) ? $Info['supportExt'] : [];

        $this->Setzen('Online', (int) ($Info['status'] ?? 0) === 1);
        if (array_key_exists('globalStatus', $Status)) {
            $this->Setzen('Bewegungserkennung', (bool) $Status['globalStatus']);
        }

        // Schalter, die die Kamera meldet
        foreach ((array) ($D['SWITCH'] ?? []) as $S) {
            $Typ = (int) ($S['type'] ?? -1);
            if (!isset(EZVIZ::SCHALTER[$Typ])) {
                continue;
            }
            $Ident = 'Schalter' . $Typ;
            if (!@$this->GetIDForIdent($Ident)) {
                [, $Name, $Icon] = EZVIZ::SCHALTER[$Typ];
                $this->RegisterVariableBoolean($Ident, $Name, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => $Icon], 10 + array_search($Typ, array_keys(EZVIZ::SCHALTER), true));
                $this->EnableAction($Ident);
            }
            $this->Setzen($Ident, (bool) ($S['enable'] ?? false));
        }

        // Schwenken (nur bei Schwenk-/Neigekameras)
        if (($Support['154'] ?? '0') === '1' || ($Support['31'] ?? '0') === '1' || ($Support['30'] ?? '0') === '1') {
            if (!@$this->GetIDForIdent('Schwenken')) {
                $Optionen2 = [];
                foreach (self::RICHTUNGEN as $Wert => [, $Text, $Icon]) {
                    $Optionen2[] = self::Option($Wert, $Text, $Icon);
                }
                $this->RegisterVariableInteger('Schwenken', 'Schwenken', [
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'ICON'         => 'arrows-up-down-left-right',
                    'OPTIONS'      => json_encode($Optionen2)
                ], 20);
                $this->EnableAction('Schwenken');
            }
        }

        // Akku (nur Akku-Kameras)
        if (isset($Optionen['powerRemaining']) && is_numeric($Optionen['powerRemaining'])) {
            $this->AkkuVerarbeiten((int) $Optionen['powerRemaining'], $Neu);
        }

        // WLAN
        $Wifi = $D['WIFI'] ?? [];
        if (isset($Wifi['signal']) && is_numeric($Wifi['signal'])) {
            if (!@$this->GetIDForIdent('WLAN')) {
                $this->RegisterVariableInteger('WLAN', 'WLAN-Signal', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'ICON' => 'wifi'], 31);
            }
            $this->Setzen('WLAN', (int) $Wifi['signal']);
        }

        $this->Setzen('Firmware', (string) ($Info['version'] ?? ''));
        $this->Setzen('Update', (int) ($D['UPGRADE']['isNeedUpgrade'] ?? 0) === 3);

        // Lokale IP und Livestream
        $IP = (string) ($Wifi['address'] ?? '');
        if ($IP === '' || $IP === '0.0.0.0') {
            $IP = (string) ($D['CONNECTION']['localIp'] ?? '');
        }
        if ($IP !== '' && $IP !== '0.0.0.0') {
            $this->WriteAttributeString('LokaleIP', $IP);
        }
        $this->LivestreamAktualisieren();

        // Letzter Alarm
        if (is_array($D['Alarm'] ?? null)) {
            $this->AlarmVerarbeiten($D['Alarm'], $Neu);
        }

        if ($Neu) {
            $this->Setzen('Zeitpunkt', time());
            $this->KachelSenden();
        }
        if ($this->GetStatus() != IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
        }
    }

    private function AlarmVerarbeiten(array $A, bool $Neu): void
    {
        $Id = (string) ($A['id'] ?? '');
        $Zeit = (int) ($A['zeit'] ?? 0);
        if ($Id === '' || $Id === $this->ReadAttributeString('AlarmId')) {
            return;
        }
        $Erster = ($this->ReadAttributeString('AlarmId') === '');
        $this->WriteAttributeString('AlarmId', $Id);

        $this->Setzen('LetzterAlarm', $Zeit);
        $this->Setzen('Alarmart', (string) ($A['text'] ?? ''));

        // Bewegung melden – beim allerersten Abruf nur, wenn der Alarm frisch ist
        $Dauer = max(10, $this->ReadPropertyInteger('Bewegungsdauer'));
        $Alter = time() - $Zeit;
        if ($Neu && (!$Erster || $Alter < $Dauer)) {
            $this->Setzen('Bewegung', true);
            $Rest = $Erster ? max(5, $Dauer - $Alter) : $Dauer;
            $this->SetTimerInterval('BewegungAus', $Rest * 1000);
        }

        if ($this->ReadPropertyBoolean('Alarmbild') && (string) ($A['bild'] ?? '') !== '') {
            $this->AlarmbildLaden((string) $A['bild']);
        }
        // Bei neuem Alarm gleich ein aktuelles Standbild holen
        if ($Neu && !$Erster && $this->ReadPropertyBoolean('Standbild')) {
            $this->SetTimerInterval('StandbildSofort', 1000);
        }
    }

    private function AlarmbildLaden(string $Url): void
    {
        $Bild = $this->BildHerunterladen($Url, 'Alarmbild');
        if ($Bild === null) {
            return;
        }

        $ID = $this->Medium('Alarmbild', MEDIATYPE_IMAGE, 'Alarmbild', 41);
        IPS_SetMediaFile($ID, 'media/EZVIZ_' . $this->InstanceID . '.jpg', false);
        IPS_SetMediaContent($ID, base64_encode($Bild));
        if (!$this->ReadPropertyBoolean('Standbild')) {
            $this->KachelSenden(true);
        }
    }

    /**
     * Lädt ein Bild aus der EZVIZ-Cloud und entschlüsselt es bei Bedarf.
     */
    private function BildHerunterladen(string $Url, string $Was): ?string
    {
        $ch = curl_init($Url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $Bild = curl_exec($ch);
        $Code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($Bild) || $Bild === '' || $Code != 200) {
            $this->SendDebug($Was, 'Download fehlgeschlagen (HTTP ' . $Code . ')', 0);
            return null;
        }
        $Bild = EZVIZ::BildEntschluesseln($Bild, $this->ReadPropertyString('Verifizierungscode'));
        if ($Bild === null) {
            $this->SendDebug($Was, 'Bild ist verschlüsselt – Verifizierungscode fehlt oder ist falsch', 0);
        }
        return $Bild;
    }

    /**
     * Standbild lokal: ein einzelnes Bild per FFmpeg aus dem RTSP-Stream.
     */
    private function StandbildLokal(array &$Fehler): ?string
    {
        $FFmpeg = $this->FFmpegFinden();
        $Url = $this->StreamUrl(false);
        if ($FFmpeg === '' || $Url === '') {
            $Fehler[] = 'lokal: ' . ($FFmpeg === '' ? 'FFmpeg nicht gefunden' : 'IP-Adresse unbekannt');
            $this->SendDebug('Standbild lokal', end($Fehler), 0);
            return null;
        }
        $Datei = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ezviz_' . $this->InstanceID . '.jpg';
        @unlink($Datei);

        $Befehl = escapeshellarg($FFmpeg) . ' -hide_banner -loglevel error -rtsp_transport tcp -timeout 8000000 -i ' . escapeshellarg($Url)
            . ' -frames:v 1 -q:v 4 -y ' . escapeshellarg($Datei);
        $Start = microtime(true);
        [$Code, $Ausgabe] = self::Ausfuehren($Befehl, 15);
        $Bild = is_file($Datei) ? file_get_contents($Datei) : false;
        @unlink($Datei);
        if ($Code !== 0 || !is_string($Bild) || $Bild === '') {
            $Grund = $Code === -9 ? 'Zeitüberschreitung (Kamera antwortet nicht)' : trim(implode(' ', array_slice($Ausgabe, -2)));
            if (stripos($Grund, '401') !== false || stripos($Grund, 'Unauthorized') !== false) {
                $Grund = 'Zugang abgelehnt – Verifizierungscode prüfen';
            } elseif (stripos($Grund, 'refused') !== false) {
                $Grund = 'Kamera lehnt RTSP ab – in der EZVIZ-App unter Einstellungen → Lokale Dienste RTSP einschalten';
            } elseif (stripos($Grund, '404') !== false || stripos($Grund, 'Not Found') !== false) {
                $Grund = 'Stream-Pfad gibt es nicht – unter Livestream einen anderen Stream wählen';
            } elseif (stripos($Grund, 'No route') !== false || stripos($Grund, 'unreachable') !== false) {
                $Grund = 'Kamera im Netz nicht erreichbar – IP-Adresse prüfen';
            }
            $Fehler[] = 'lokal: ' . ($Grund !== '' ? mb_substr($Grund, 0, 140) : 'Fehler ' . $Code);
            $this->SendDebug('Standbild lokal', 'fehlgeschlagen (' . $Code . '): ' . implode(' ', $Ausgabe), 0);
            return null;
        }
        $this->SendDebug('Standbild lokal', strlen($Bild) . ' Bytes in ' . round(microtime(true) - $Start, 1) . ' s', 0);
        return $Bild;
    }

    /**
     * Standbild über die Cloud: die Kamera macht ein Foto und lädt es hoch.
     */
    /**
     * Führt einen Befehl mit fester Zeitgrenze aus (unabhängig vom System-Befehl "timeout").
     * Liefert [Exitcode, Ausgabezeilen]; -9 = abgebrochen wegen Zeitüberschreitung, -1 = Start nicht möglich.
     */
    private static function Ausfuehren(string $Befehl, int $Sekunden): array
    {
        // In Symcon sind einzelne PHP-Funktionen gesperrt – der Reihe nach probieren
        if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
            if (function_exists('exec')) {
                $Ausgabe = [];
                $Code = 0;
                if (DIRECTORY_SEPARATOR === '/' && is_executable('/usr/bin/timeout')) {
                    $Befehl = '/usr/bin/timeout ' . $Sekunden . ' ' . $Befehl;
                }
                @exec($Befehl . ' 2>&1', $Ausgabe, $Code);
                return [$Code === 124 ? -9 : $Code, $Ausgabe];
            }
            if (function_exists('IPS_ExecuteEx')) {
                $Programm = DIRECTORY_SEPARATOR === '/' ? '/bin/sh' : 'cmd.exe';
                $Parameter = DIRECTORY_SEPARATOR === '/' ? '-c ' . escapeshellarg($Befehl . ' 2>&1') : '/C ' . $Befehl;
                $Text = (string) @IPS_ExecuteEx($Programm, $Parameter, false, true, -1);
                return [0, array_values(array_filter(array_map('trim', explode("\n", $Text))))];
            }
            return [-1, ['Programme starten ist in diesem Symcon nicht erlaubt']];
        }
        // "exec": die Shell ersetzt sich durch FFmpeg – so beendet ein Abbruch FFmpeg selbst und nicht nur die Shell
        $Prozess = @proc_open((DIRECTORY_SEPARATOR === '/' ? 'exec ' : '') . $Befehl, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Rohre);
        if (!is_resource($Prozess)) {
            return [-1, ['Programm konnte nicht gestartet werden']];
        }
        fclose($Rohre[0]);
        stream_set_blocking($Rohre[1], false);
        stream_set_blocking($Rohre[2], false);
        $Text = '';
        $Ende = microtime(true) + $Sekunden;
        $Code = null;
        while (true) {
            $Text .= (string) stream_get_contents($Rohre[1]) . (string) stream_get_contents($Rohre[2]);
            $Status = proc_get_status($Prozess);
            if (!$Status['running']) {
                $Code = $Status['exitcode'];
                break;
            }
            if (microtime(true) > $Ende) {
                proc_terminate($Prozess, 9);
                $Code = -9;
                break;
            }
            usleep(100000);
        }
        $Text .= (string) stream_get_contents($Rohre[1]) . (string) stream_get_contents($Rohre[2]);
        fclose($Rohre[1]);
        fclose($Rohre[2]);
        $Rest = proc_close($Prozess);
        if ($Code === null || $Code === -1) {
            $Code = $Rest;
        }
        return [(int) $Code, array_values(array_filter(array_map('trim', explode("\n", $Text))))];
    }

    private function StandbildCloud(array &$Fehler): ?string
    {
        $Result = $this->Senden('PUT', '/v3/devconfig/v1/' . $this->Serial() . '/1/capture');
        if (!$Result['Success']) {
            $Fehler[] = 'Cloud: ' . trim($Result['Error']);
            $this->SendDebug('Standbild Cloud', 'fehlgeschlagen: ' . $Result['Error'] . ' ' . json_encode($Result['Data']), 0);
            return null;
        }
        $Url = self::BildUrlSuchen($Result['Data']);
        if ($Url === null) {
            $Fehler[] = 'Cloud: keine Bildadresse';
            $this->SendDebug('Standbild Cloud', 'keine Bildadresse in der Antwort: ' . json_encode($Result['Data']), 0);
            return null;
        }
        $Bild = $this->BildHerunterladen($Url, 'Standbild Cloud');
        if ($Bild === null) {
            $Fehler[] = 'Cloud: Bild nicht ladbar oder Verifizierungscode falsch';
        }
        return $Bild;
    }

    private static function BildUrlSuchen($Wert): ?string
    {
        if (is_string($Wert)) {
            foreach (explode(';', $Wert) as $Teil) {
                $Teil = trim($Teil);
                if (strpos($Teil, 'http://') === 0 || strpos($Teil, 'https://') === 0) {
                    return $Teil;
                }
            }
            return null;
        }
        if (!is_array($Wert)) {
            return null;
        }
        foreach (['picUrl', 'picURL', 'imageUrl', 'imageURL', 'captureUrl', 'captureURL', 'pic', 'pics', 'image', 'url'] as $Key) {
            if (isset($Wert[$Key]) && is_string($Wert[$Key]) && ($Url = self::BildUrlSuchen($Wert[$Key])) !== null) {
                return $Url;
            }
        }
        foreach ($Wert as $Teil) {
            if (is_array($Teil) && ($Url = self::BildUrlSuchen($Teil)) !== null) {
                return $Url;
            }
        }
        return null;
    }

    private function FFmpegFinden(): string
    {
        $Pfad = trim($this->ReadPropertyString('FFmpeg'));
        if ($Pfad !== '') {
            return $this->Ausfuehrbar($Pfad) ? $Pfad : '';
        }
        $Gemerkt = $this->ReadAttributeString('FFmpegPfad');
        if ($Gemerkt !== '' && is_file($Gemerkt)) {
            return $Gemerkt;
        }
        $Gefunden = '';
        // Einfachster Weg (z. B. Symcon im Docker-Container): Datei "ffmpeg" in den Symcon-Ordner legen
        foreach (['ffmpeg', 'ffmpeg.exe'] as $Name) {
            $Kandidat = rtrim(IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . $Name;
            if ($this->Ausfuehrbar($Kandidat)) {
                $this->WriteAttributeString('FFmpegPfad', $Kandidat);
                return $Kandidat;
            }
        }
        if (DIRECTORY_SEPARATOR === '/') {
            // Synology: Pakete der SynoCommunity (ffmpeg7/6/5) haben Vorrang vor dem eingeschränkten DSM-FFmpeg
            $Kandidaten = [
                '/var/packages/ffmpeg7/target/bin/ffmpeg',
                '/var/packages/ffmpeg6/target/bin/ffmpeg',
                '/var/packages/ffmpeg5/target/bin/ffmpeg',
                '/var/packages/ffmpeg/target/bin/ffmpeg',
                '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/bin/ffmpeg'
            ];
            foreach ($Kandidaten as $Kandidat) {
                if (is_executable($Kandidat)) {
                    $Gefunden = $Kandidat;
                    break;
                }
            }
        } else {
            $Ausgabe = [];
            @exec('where ffmpeg 2>NUL', $Ausgabe);
            if (isset($Ausgabe[0]) && is_file(trim($Ausgabe[0]))) {
                $Gefunden = trim($Ausgabe[0]);
            }
        }
        $this->WriteAttributeString('FFmpegPfad', $Gefunden);
        return $Gefunden;
    }

    /**
     * Prüft, ob die Datei existiert, und macht sie bei Bedarf ausführbar
     * (nach dem Hochladen per File Station fehlt dieses Recht oft).
     */
    private function Ausfuehrbar(string $Datei): bool
    {
        if (!is_file($Datei)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '/' && !is_executable($Datei)) {
            @chmod($Datei, 0755);
            clearstatcache(true, $Datei);
            if (!is_executable($Datei)) {
                $this->SendDebug('FFmpeg', $Datei . ' ist nicht ausführbar', 0);
                return false;
            }
        }
        return true;
    }

    /**
     * Intervall des automatischen Standbilds: [Millisekunden, Grund wenn aus]
     */
    private function AutoIntervall(): array
    {
        if (!$this->ReadPropertyBoolean('Standbild')) {
            return [0, 'Standbild ist ausgeschaltet'];
        }
        $Sekunden = $this->ReadPropertyInteger('StandbildIntervall');
        if ($Sekunden <= 0) {
            return [0, 'Intervall ist 0 (nur bei Alarm/auf Knopfdruck)'];
        }
        // Akku-Kameras: regelmäßige Fotos würden den Akku leeren – nur bei Alarm/auf Knopfdruck
        if ($this->HatAkku() && !$this->ReadPropertyBoolean('StandbildAkku')) {
            return [0, 'Akku-Kamera – unter „Standbild“ „Auch bei Akku-Kameras regelmäßig aktualisieren“ einschalten'];
        }
        return [max(10, $Sekunden) * 1000, ''];
    }

    private function StandbildTimer(): void
    {
        [$Ms] = $this->AutoIntervall();
        if ($this->GetTimerInterval('Standbild') !== $Ms) {
            $this->SetTimerInterval('Standbild', $Ms);
        }
    }

    private function Schlaeft(): bool
    {
        $ID = @$this->GetIDForIdent('Schalter21');
        return $ID && GetValue($ID) === true;
    }

    // ---------- Kachel ----------

    private function KachelDaten(bool $MitBild): array
    {
        $Wert = function (string $Ident, $Standard) {
            $ID = @$this->GetIDForIdent($Ident);
            return $ID ? GetValue($ID) : $Standard;
        };
        $Daten = json_decode($this->ReadAttributeString('Daten'), true);
        $Support = is_array($Daten['deviceInfos']['supportExt'] ?? null) ? $Daten['deviceInfos']['supportExt'] : [];

        $K = [
            'name'       => IPS_GetName($this->InstanceID),
            'online'     => (bool) $Wert('Online', false),
            'schutz'     => (bool) $Wert('Bewegungserkennung', false),
            'bewegung'   => (bool) $Wert('Bewegung', false),
            'schlaf'     => $this->Schlaeft(),
            'hatSchlaf'  => (bool) @$this->GetIDForIdent('Schalter21'),
            'alarm'      => (int) $Wert('LetzterAlarm', 0),
            'alarmText'  => (string) $Wert('Alarmart', ''),
            'ptz'        => (bool) @$this->GetIDForIdent('Schwenken'),
            'akku'       => @$this->GetIDForIdent('Akku') ? (int) $Wert('Akku', 0) : null,
            'akkuSchwach'=> (bool) $Wert('AkkuSchwach', false),
            'bildZeit'   => $this->ReadAttributeInteger('StandbildZeit'),
            'bildArt'    => 'Standbild',
            'fehler'     => $this->ReadAttributeString('StandbildFehler'),
            'intervall'  => (int) ($this->AutoIntervall()[0] / 1000)
        ];
        if ($MitBild) {
            $K['bild'] = '';
            foreach (['Standbild', 'Alarmbild'] as $Ident) {
                $ID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
                if ($ID !== false && IPS_MediaExists($ID)) {
                    $Inhalt = (string) @IPS_GetMediaContent($ID);
                    if ($Inhalt !== '') {
                        $K['bild'] = $Inhalt;
                        if ($Ident === 'Alarmbild') {
                            $K['bildZeit'] = $K['alarm'];
                            $K['bildArt'] = 'Alarmbild';
                        }
                        break;
                    }
                }
            }
        }
        return $K;
    }

    private function KachelSenden(bool $MitBild = false): void
    {
        if ($this->ReadPropertyBoolean('Kachel')) {
            $this->UpdateVisualizationValue(json_encode($this->KachelDaten($MitBild)));
        }
    }

    private function KachelAktion(string $Wert): void
    {
        $A = json_decode($Wert, true);
        if (!is_array($A)) {
            return;
        }
        switch ((string) ($A['aktion'] ?? '')) {
            case 'bild':
                // Automatische Anfragen der Kachel: nicht öfter als im Intervall (mehrere Geräte offen)
                $Ms = $this->AutoIntervall()[0];
                if (!empty($A['auto']) && ($Ms <= 0 || time() - $this->ReadAttributeInteger('StandbildZeit') < $Ms / 1000 - 5)) {
                    return;
                }
                $this->UpdateSnapshot();
                break;
            case 'schwenken':
                $this->Move((string) ($A['richtung'] ?? ''));
                $this->SetTimerInterval('StandbildSofort', 1500);
                break;
            case 'schutz':
                $this->SetMotionDetection(!(bool) GetValue($this->GetIDForIdent('Bewegungserkennung')));
                break;
            case 'schlaf':
                if (@$this->GetIDForIdent('Schalter21')) {
                    $this->SetSwitch(21, !$this->Schlaeft());
                }
                break;
        }
        $this->KachelSenden();
    }

    // ---------- Akku ----------

    private function AkkuVerarbeiten(int $Stand, bool $Neu): void
    {
        $Stand = max(0, min(100, $Stand));
        if (!@$this->GetIDForIdent('Akku')) {
            $this->RegisterVariableInteger('Akku', 'Akku', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'ICON' => 'battery-half'], 30);
            $this->RegisterVariableBoolean('AkkuSchwach', 'Akku schwach', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'battery-quarter'], 31);
            $this->StandbildTimer();
        }
        $this->Setzen('Akku', $Stand);

        $Grenze = $this->ReadPropertyInteger('AkkuGrenze');
        $Schwach = $Grenze > 0 && $Stand <= $Grenze;
        $Gemeldet = $this->ReadAttributeBoolean('AkkuGemeldet');

        if ($Schwach) {
            $this->Setzen('AkkuSchwach', true);
            // Nur einmal melden, bis der Akku wieder geladen ist
            if ($Neu && !$Gemeldet) {
                $this->WriteAttributeBoolean('AkkuGemeldet', true);
                $this->AkkuMelden($Stand);
            }
        } elseif ($Stand >= $Grenze + 5) {
            // 5 % Abstand, damit ein schwankender Wert nicht ständig neu meldet
            $this->Setzen('AkkuSchwach', false);
            if ($Gemeldet) {
                $this->WriteAttributeBoolean('AkkuGemeldet', false);
            }
        }
    }

    private function AkkuMelden(int $Stand): void
    {
        $Name = IPS_GetName($this->InstanceID);
        $Text = 'Akku von „' . $Name . '“ nur noch ' . $Stand . ' % – bitte laden.';
        $this->LogMessage($Text, KL_WARNING);

        if (!$this->ReadPropertyBoolean('AkkuMeldung')) {
            return;
        }
        $Visu = $this->ReadPropertyInteger('VisuID');
        if ($Visu <= 0 || !IPS_InstanceExists($Visu)) {
            $this->SendDebug('Akku', 'Keine Visualisierung für die Meldung ausgewählt', 0);
            return;
        }
        $Titel = mb_substr('Akku schwach: ' . $Name, 0, 32);
        if (function_exists('VISU_PostNotification')) {
            // Antippen öffnet die Kamera; liegt sie nicht in der Visualisierung, ohne Ziel senden
            $Ok = @VISU_PostNotification($Visu, $Titel, $Text, 'Warning', $this->InstanceID);
            if ($Ok === false) {
                $Ok = @VISU_PostNotification($Visu, $Titel, $Text, 'Warning', 0);
            }
            $this->SendDebug('Akku', 'Meldung an Visualisierung ' . ($Ok !== false ? 'gesendet' : 'fehlgeschlagen'), 0);
        } elseif (function_exists('WFC_PushNotification')) {
            @WFC_PushNotification($Visu, $Titel, $Text, '', $this->InstanceID);
            $this->SendDebug('Akku', 'Meldung an WebFront gesendet', 0);
        }
    }

    /**
     * Schickt eine Test-Meldung „Akku schwach“ an die ausgewählte Visualisierung.
     */
    public function TestBatteryNotification(): bool
    {
        $ID = @$this->GetIDForIdent('Akku');
        $this->AkkuMelden($ID ? (int) GetValue($ID) : $this->ReadPropertyInteger('AkkuGrenze'));
        return $this->ReadPropertyInteger('VisuID') > 0;
    }

    private function LivestreamAktualisieren(): void
    {
        if (!$this->ReadPropertyBoolean('Livestream')) {
            return;
        }
        $Url = $this->StreamUrl(false);
        if ($Url === '') {
            return;
        }
        $ID = $this->Medium('Livestream', MEDIATYPE_STREAM, 'Livestream', 40);
        if (IPS_GetMedia($ID)['MediaFile'] !== $Url) {
            IPS_SetMediaFile($ID, $Url, false);
        }
    }

    private function StreamUrl(bool $Maskiert): string
    {
        $IP = $this->IPAdresse();
        if ($IP === '') {
            return '';
        }
        $Code = $this->ReadPropertyString('Verifizierungscode');
        $Benutzer = $this->ReadPropertyString('Benutzer');
        $Zugang = '';
        if ($Benutzer !== '') {
            $Zugang = rawurlencode($Benutzer) . ($Code !== '' ? ':' . ($Maskiert ? '******' : rawurlencode($Code)) : '') . '@';
        }
        $Pfad = self::STREAMS[$this->ReadPropertyInteger('Stream')] ?? '';
        if ($Pfad === '') {
            $Pfad = '/' . ltrim($this->ReadPropertyString('Pfad'), '/');
        }
        return 'rtsp://' . $Zugang . $IP . ':' . $this->ReadPropertyInteger('Port') . $Pfad;
    }

    private function IPAdresse(): string
    {
        $IP = trim($this->ReadPropertyString('IP'));
        return $IP !== '' ? $IP : $this->ReadAttributeString('LokaleIP');
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function GrundvariablenAnlegen(): void
    {
        $Datum = defined('VARIABLE_PRESENTATION_DATE_TIME')
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME]
            : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $Anzeige = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];

        $this->RegisterVariableBoolean('Online', 'Online', $Anzeige + ['ICON' => 'signal'], 1);

        $this->RegisterVariableBoolean('Bewegungserkennung', 'Bewegungserkennung', ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'shield-halved'], 2);
        $this->EnableAction('Bewegungserkennung');

        $this->RegisterVariableBoolean('Bewegung', 'Bewegung erkannt', $Anzeige + ['ICON' => 'person-walking'], 3);
        $this->RegisterVariableInteger('LetzterAlarm', 'Letzter Alarm', $Datum + ['ICON' => 'bell'], 4);
        $this->RegisterVariableString('Alarmart', 'Alarmart', $Anzeige + ['ICON' => 'circle-info'], 5);

        $this->RegisterVariableString('Firmware', 'Firmware', $Anzeige + ['ICON' => 'microchip'], 32);
        $this->RegisterVariableBoolean('Update', 'Firmware-Update verfügbar', $Anzeige + ['ICON' => 'download'], 33);

        $this->RegisterVariableInteger('Aktualisieren', 'Aktualisieren', [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'arrows-rotate',
            'OPTIONS'      => json_encode([self::Option(0, 'Aktualisieren', '')])
        ], 50);
        $this->EnableAction('Aktualisieren');

        $this->RegisterVariableInteger('Zeitpunkt', 'Letzte Aktualisierung', $Datum + ['ICON' => 'clock'], 51);
    }

    private static function Option(int $Wert, string $Text, string $Icon): array
    {
        return [
            'Value'       => $Wert,
            'Caption'     => $Text,
            'IconActive'  => $Icon !== '',
            'IconValue'   => $Icon,
            'ColorActive' => false,
            'ColorValue'  => -1
        ];
    }

    private function Setzen(string $Ident, mixed $Wert): void
    {
        $ID = @$this->GetIDForIdent($Ident);
        if ($ID && GetValue($ID) !== $Wert) {
            $this->SetValue($Ident, $Wert);
        }
    }

    private function Medium(string $Ident, int $Typ, string $Name, int $Position): int
    {
        $ID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($ID === false || !IPS_MediaExists($ID)) {
            $ID = IPS_CreateMedia($Typ);
            IPS_SetParent($ID, $this->InstanceID);
            IPS_SetIdent($ID, $Ident);
            IPS_SetName($ID, $Name);
            IPS_SetPosition($ID, $Position);
            if ($Typ === MEDIATYPE_IMAGE) {
                IPS_SetMediaCached($ID, true);
            }
        }
        return $ID;
    }

    private function MedienLoeschen(string $Ident): void
    {
        $ID = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        if ($ID !== false && IPS_MediaExists($ID)) {
            IPS_DeleteMedia($ID, true);
        }
    }

    private function Senden(string $Method, string $Path, array $Form = [], array $Query = []): array
    {
        if (!$this->HasActiveParent()) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Konto nicht verbunden'];
        }
        return EZVIZ::Response(@$this->SendDataToParent(EZVIZ::Request('Anfrage', [
            'Method' => $Method,
            'Path'   => $Path,
            'Query'  => $Query,
            'Form'   => $Form
        ])));
    }

    private function Fehler(string $Was, array $Result): void
    {
        $this->SendDebug($Was, 'fehlgeschlagen: ' . $Result['Error'], 0);
        $this->LogMessage('EZVIZ ' . IPS_GetName($this->InstanceID) . ': ' . $Was . ' fehlgeschlagen (' . $Result['Error'] . ')', KL_WARNING);
    }

    private function Serial(): string
    {
        return rawurlencode(trim($this->ReadPropertyString('Serial')));
    }

    private static function Uuid(): string
    {
        $B = random_bytes(16);
        $B[6] = chr((ord($B[6]) & 0x0f) | 0x40);
        $B[8] = chr((ord($B[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($B), 4));
    }

    private function ParentBeobachten(): void
    {
        $Alt = $this->ReadAttributeInteger('ParentID');
        $Neu = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($Alt != $Neu) {
            if ($Alt > 0) {
                $this->UnregisterMessage($Alt, IM_CHANGESTATUS);
            }
            if ($Neu > 0) {
                $this->RegisterMessage($Neu, IM_CHANGESTATUS);
            }
            $this->WriteAttributeInteger('ParentID', $Neu);
        }
        $this->AbrufBeobachten();
    }

    /**
     * Beobachtet die Variable „Letzter Abruf“ des Kontos (Signal für neue Daten).
     */
    private function AbrufBeobachten(): void
    {
        $Parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $Neu = $Parent > 0 ? (int) @IPS_GetObjectIDByIdent('Abruf', $Parent) : 0;
        $Alt = $this->ReadAttributeInteger('AbrufID');
        if ($Alt === $Neu) {
            return;
        }
        if ($Alt > 0) {
            @$this->UnregisterMessage($Alt, VM_UPDATE);
        }
        if ($Neu > 0) {
            $this->RegisterMessage($Neu, VM_UPDATE);
        }
        $this->WriteAttributeInteger('AbrufID', $Neu);
    }
}
