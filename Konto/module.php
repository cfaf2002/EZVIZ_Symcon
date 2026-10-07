<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EZVIZ.php';

/**
 * EZVIZ Konto
 * Meldet sich am EZVIZ-Konto an, verwaltet die Sitzung, fragt regelmäßig
 * alle Geräte samt letzter Alarme ab und verteilt sie an die Kamera-Instanzen.
 *
 * Autor: Armin Frohwerk
 */
class EZVIZKonto extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('HinweisBestaetigt', false);
        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyString('EMail', '');
        $this->RegisterPropertyString('Passwort', '');
        $this->RegisterPropertyString('Server', EZVIZ::SERVER_STANDARD);
        $this->RegisterPropertyString('Code', '');
        $this->RegisterPropertyInteger('Intervall', 60);
        $this->RegisterPropertyBoolean('Push', false);

        $this->RegisterAttributeString('FeatureCode', '');
        $this->RegisterAttributeString('SessionId', '');
        $this->RegisterAttributeString('RefreshId', '');
        $this->RegisterAttributeString('ApiDomain', '');
        $this->RegisterAttributeString('Benutzer', '');
        $this->RegisterAttributeString('LoginHash', '');
        $this->RegisterAttributeString('CodeVerwendet', '');
        $this->RegisterAttributeString('Cache', '{}');
        $this->RegisterAttributeInteger('Stand', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('UserId', '');
        $this->RegisterAttributeString('PushServer', '');
        $this->RegisterAttributeInteger('Fehlversuche', 0);
        $this->RegisterAttributeInteger('WartenBis', 0);

        $this->RegisterTimer('Aktualisieren', 0, 'EZVIZ_RefreshAll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Aktualisieren', 0);

        $Datum = defined('VARIABLE_PRESENTATION_DATE_TIME')
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME]
            : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->RegisterVariableInteger('Abruf', 'Letzter Abruf', $Datum + ['ICON' => 'clock'], 1);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if ($this->ReadAttributeString('FeatureCode') === '') {
            $this->WriteAttributeString('FeatureCode', md5(uniqid('symcon', true) . random_bytes(8)));
        }

        $this->PushInstanzPruefen();
        // Übernehmen startet sofort einen neuen Anmeldeversuch
        $this->WartezeitZuruecksetzen();

        if (!$this->Pruefen()) {
            return;
        }

        // Zugangsdaten, Server oder Push geändert? Dann alte Sitzung verwerfen.
        if ($this->LoginHash() !== $this->ReadAttributeString('LoginHash')) {
            $this->SitzungLoeschen();
            $this->WriteAttributeString('ApiDomain', '');
            $this->WriteAttributeString('PushServer', '');
        }

        $this->IntervallSetzen();
        if ($this->Verbinden()) {
            $this->Aktualisieren();
        }
    }

    /**
     * Zugangsdaten, Server und Push-Schalter – ändert sich etwas, ist eine neue Anmeldung nötig
     * (Push braucht eine Anmeldung wie die Android-App).
     */
    private function LoginHash(): string
    {
        return md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort') . '|' . $this->ReadPropertyString('Server')
            . ($this->ReadPropertyBoolean('Push') ? '|push' : ''));
    }

    /**
     * Legt die Push-Instanz samt Client Socket an bzw. entfernt sie wieder.
     */
    private function PushInstanzPruefen(): void
    {
        $Vorhanden = 0;
        foreach (IPS_GetInstanceListByModuleID(EZVIZ::MODUL_PUSH) as $ID) {
            if ((int) IPS_GetProperty($ID, 'KontoID') === $this->InstanceID) {
                $Vorhanden = $ID;
                break;
            }
        }
        // Nur der Push-Schalter entscheidet – ein kurz deaktiviertes Konto soll die Push-Instanz
        // samt Variablen (Ereignisse, Verknüpfungen) nicht löschen
        $An = $this->ReadPropertyBoolean('Push');
        if ($An && $Vorhanden === 0) {
            $IO = IPS_CreateInstance(EZVIZ::CLIENT_SOCKET);
            IPS_SetName($IO, 'EZVIZ Push Verbindung');
            IPS_SetProperty($IO, 'Open', false);
            IPS_ApplyChanges($IO);

            $Push = IPS_CreateInstance(EZVIZ::MODUL_PUSH);
            IPS_SetName($Push, 'EZVIZ Push (Sofort-Alarme)');
            IPS_SetParent($Push, $this->InstanceID);
            IPS_SetProperty($Push, 'KontoID', $this->InstanceID);
            if (IPS_GetInstance($Push)['ConnectionID'] > 0) {
                IPS_DisconnectInstance($Push);
            }
            IPS_ConnectInstance($Push, $IO);
            IPS_ApplyChanges($Push);
            $this->LogMessage('Push für Sofort-Alarme eingeschaltet (Instanz #' . $Push . ')', KL_MESSAGE);
        } elseif (!$An && $Vorhanden > 0) {
            $IO = IPS_GetInstance($Vorhanden)['ConnectionID'];
            IPS_DeleteInstance($Vorhanden);
            if ($IO > 0 && IPS_InstanceExists($IO)) {
                $Kinder = array_filter(IPS_GetInstanceList(), function ($ID) use ($IO) {
                    return IPS_GetInstance($ID)['ConnectionID'] == $IO;
                });
                if (!count($Kinder)) {
                    IPS_DeleteInstance($IO);
                }
            }
            $this->LogMessage('Push für Sofort-Alarme ausgeschaltet', KL_MESSAGE);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Aktualisieren':
                $this->Aktualisieren();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $Info = 'Nicht angemeldet';
        if ($this->ReadAttributeString('SessionId') !== '') {
            $Benutzer = $this->ReadAttributeString('Benutzer');
            $Info = 'Angemeldet als ' . ($Benutzer !== '' ? $Benutzer : $this->ReadPropertyString('EMail'))
                . ' – Server ' . $this->ApiDomain();
            $Stand = $this->ReadAttributeInteger('Stand');
            if ($Stand > 0) {
                $Info .= ' – letzter Abruf ' . date('d.m.Y H:i:s', $Stand);
            }
        }
        $Ms = $this->GetTimerInterval('Aktualisieren');
        $Info .= ' – automatischer Abruf ' . ($Ms > 0 ? 'alle ' . round($Ms / 1000) . ' s' : 'AUS');
        $Fehler = $this->ReadAttributeString('LetzterFehler');
        if ($Fehler !== '') {
            $Info .= ' – letzter Fehler ' . $Fehler;
        }
        foreach ($Form['actions'] as &$Element) {
            if (($Element['name'] ?? '') === 'Kontoinfo') {
                $Element['caption'] = $Info;
            }
        }
        return json_encode($Form);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (EZVIZ_...)
    // ------------------------------------------------------------------

    /**
     * Meldet sich neu an (z. B. über den Button "Verbindung testen").
     */
    public function Login(): bool
    {
        if (!$this->Pruefen()) {
            return false;
        }
        $this->WartezeitZuruecksetzen();
        $Ok = $this->Exklusiv(function (): bool {
            $this->SitzungLoeschen();
            return $this->Anmelden();
        });
        if ($Ok) {
            $this->Aktualisieren();
        }
        $this->ReloadForm();
        return $Ok;
    }

    /**
     * Liefert alle Geräte des Kontos: [Seriennummer => ['name', 'model', 'category', 'online']]
     */
    public function GetDevices(): array
    {
        $Liste = [];
        foreach ($this->CacheLesen() as $Serial => $Geraet) {
            $Info = $Geraet['deviceInfos'] ?? [];
            $Liste[(string) $Serial] = [
                'name'     => (string) ($Info['name'] ?? $Serial),
                'model'    => (string) ($Info['deviceType'] ?? ''),
                'category' => (string) ($Info['deviceCategory'] ?? ''),
                'online'   => (int) ($Info['status'] ?? 0) === 1
            ];
        }
        return $Liste;
    }

    /**
     * Fragt alle Geräte sofort neu ab und verteilt die Daten an die Kameras.
     */
    public function RefreshAll(): bool
    {
        return $this->Aktualisieren();
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
     * Für die Push-Instanz: meldet den Push-Kanal bei EZVIZ an und liefert die Zugangsdaten.
     * Ergebnis: ['Success', 'Error', 'Serial', 'Session', 'Host', 'Port']
     */
    public function GetPushInfo(): array
    {
        $Fehler = function (string $Text): array {
            return ['Success' => false, 'Error' => $Text];
        };
        if (!$this->ReadPropertyBoolean('Push')) {
            return $Fehler('Push ist im Konto ausgeschaltet');
        }
        if (!$this->Pruefen() || !$this->Verbinden(true)) {
            return $Fehler('Konto ist nicht angemeldet');
        }
        // Ältere Anmeldung ohne Benutzer-ID: einmal neu anmelden
        if ($this->ReadAttributeString('UserId') === '') {
            $Ok = $this->Exklusiv(function (): bool {
                if ($this->ReadAttributeString('UserId') !== '') {
                    return true; // inzwischen von einem anderen Ablauf erledigt
                }
                $this->SitzungLoeschen();
                return $this->Anmelden();
            });
            if (!$Ok) {
                return $Fehler('Neue Anmeldung fehlgeschlagen');
            }
        }
        $Server = json_decode($this->ReadAttributeString('PushServer'), true);
        if (!is_array($Server) || empty($Server['Host'])) {
            $Result = $this->Anfrage('GET', EZVIZ::SERVER_INFO);
            $Info = is_array($Result['Data']) ? ($Result['Data']['systemConfigInfo'] ?? []) : [];
            if (!$Result['Success'] || empty($Info['pushDasDomain'])) {
                return $Fehler('Push-Server unbekannt: ' . $Result['Error']);
            }
            $Server = ['Host' => (string) $Info['pushDasDomain'], 'Port' => (int) ($Info['pushDasPort'] ?? 8666)];
            $this->WriteAttributeString('PushServer', json_encode($Server));
        }
        $Result = $this->Anfrage('PUT', EZVIZ::PUSH_TOKEN, [
            'pushRegisterJson' => EZVIZ::PUSH_REGISTER_JSON,
            'pushExtJson'      => EZVIZ::PUSH_EXT_JSON
        ]);
        if (!$Result['Success']) {
            return $Fehler('Push-Anmeldung abgelehnt: ' . $Result['Error']);
        }
        return [
            'Success' => true,
            'Error'   => '',
            'Serial'  => 'MOBILE:ys7:' . $this->ReadAttributeString('UserId') . ':' . $this->ReadAttributeString('FeatureCode'),
            'Session' => $this->ReadAttributeString('SessionId'),
            'Host'    => $Server['Host'],
            'Port'    => $Server['Port']
        ];
    }

    /**
     * Für die Push-Instanz: gleich neu abfragen (z. B. nach einem Push-Alarm).
     * Kehrt sofort zurück – der Abruf läuft über den Timer.
     */
    public function RefreshSoon(): void
    {
        $this->SetTimerInterval('Aktualisieren', 300);
    }

    /**
     * Anfragen der Kind-Instanzen.
     */
    public function ForwardData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (!is_array($Data)) {
            return json_encode(['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Ungültige Anfrage']);
        }

        switch ((string) ($Data['Befehl'] ?? '')) {
            case 'Anfrage':
                $Result = $this->Anfrage(
                    (string) ($Data['Method'] ?? 'GET'),
                    (string) ($Data['Path'] ?? ''),
                    (array) ($Data['Query'] ?? []),
                    (array) ($Data['Form'] ?? [])
                );
                break;

            case 'Status':
                $Cache = $this->CacheLesen();
                $Serial = (string) ($Data['Serial'] ?? '');
                $Result = isset($Cache[$Serial])
                    ? ['Success' => true, 'Code' => 200, 'Data' => $Cache[$Serial], 'Error' => '', 'Stand' => $this->ReadAttributeInteger('Stand')]
                    : ['Success' => false, 'Code' => 404, 'Data' => null, 'Error' => count($Cache) ? 'Gerät nicht im Konto' : 'Noch keine Daten'];
                break;

            case 'Geraete':
                $Result = ['Success' => true, 'Code' => 200, 'Data' => $this->GetDevices(), 'Error' => ''];
                break;

            case 'Aktualisieren':
                // Nicht direkt abrufen – die Kamera wartet gerade auf diese Antwort.
                $this->SetTimerInterval('Aktualisieren', 1500);
                $Result = ['Success' => true, 'Code' => 200, 'Data' => null, 'Error' => ''];
                break;

            default:
                $Result = ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Unbekannter Befehl'];
        }
        return json_encode($Result);
    }

    // ------------------------------------------------------------------
    // Abfrage aller Geräte
    // ------------------------------------------------------------------

    private function Aktualisieren(): bool
    {
        $this->IntervallSetzen();
        if (!$this->Pruefen()) {
            return false;
        }

        $Liste = $this->GeraetelisteLaden();
        if ($Liste === null) {
            $this->SendDebug('Aktualisieren', 'Geräteliste konnte nicht geladen werden', 0);
            return false;
        }
        $this->WriteAttributeString('LetzterFehler', '');

        $Geraete = [];
        foreach ($Liste['deviceInfos'] ?? [] as $Info) {
            $Serial = (string) ($Info['deviceSerial'] ?? '');
            if ($Serial === '') {
                continue;
            }
            if (isset($Info['supportExt']) && is_string($Info['supportExt'])) {
                $Info['supportExt'] = json_decode($Info['supportExt'], true) ?: [];
            }
            $Geraet = ['deviceInfos' => $Info];
            foreach (EZVIZ::ABSCHNITTE as $Abschnitt) {
                $Geraet[$Abschnitt] = $Liste[$Abschnitt][$Serial] ?? [];
            }
            if (isset($Geraet['STATUS']['optionals']) && is_string($Geraet['STATUS']['optionals'])) {
                $Geraet['STATUS']['optionals'] = json_decode($Geraet['STATUS']['optionals'], true) ?: [];
            }
            $Geraet['Alarm'] = null;
            $Geraete[$Serial] = $Geraet;
        }

        // Letzte Alarme aller Geräte mit einer Abfrage
        if (count($Geraete)) {
            foreach ($this->AlarmeLaden(array_keys($Geraete)) as $Serial => $Alarm) {
                if (isset($Geraete[$Serial])) {
                    $Geraete[$Serial]['Alarm'] = $Alarm;
                }
            }
        }

        $this->WriteAttributeString('Cache', json_encode($Geraete));
        $this->WriteAttributeInteger('Stand', time());

        // Kameras nicht direkt aufrufen: Sie holen sich ihre Daten selbst, sobald sich diese
        // Variable ändert. So können Konto und Kamera nie gleichzeitig aufeinander warten
        // (vorher konnten sich beide dauerhaft gegenseitig blockieren und alle Timer standen).
        $this->SetValue('Abruf', time());
        $this->SendDebug('Aktualisieren', count($Geraete) . ' Geräte', 0);
        return true;
    }

    private function GeraetelisteLaden(): ?array
    {
        $Gesamt = [];
        $Offset = 0;
        for ($Seite = 0; $Seite < 10; $Seite++) {
            $Result = $this->Anfrage('GET', EZVIZ::GERAETELISTE, [
                'groupId' => -1,
                'limit'   => 30,
                'offset'  => $Offset,
                'filter'  => EZVIZ::LISTE_FILTER
            ]);
            if (!$Result['Success'] || !is_array($Result['Data'])) {
                $this->FehlerMerken('Geräteliste: ' . $Result['Error']);
                return null;
            }
            $Gesamt = $this->Zusammenfuehren($Gesamt, $Result['Data']);
            if (empty($Result['Data']['page']['hasNext'])) {
                break;
            }
            $Offset += 30;
        }
        return $Gesamt;
    }

    private function Zusammenfuehren(array $A, array $B): array
    {
        foreach ($B as $Key => $Wert) {
            if (!isset($A[$Key]) || !is_array($A[$Key]) || !is_array($Wert)) {
                $A[$Key] = $Wert;
            } elseif (array_is_list($A[$Key]) && array_is_list($Wert)) {
                $A[$Key] = array_merge($A[$Key], $Wert);
            } else {
                $A[$Key] = $A[$Key] + $Wert;
            }
        }
        return $A;
    }

    /**
     * Neueste Meldung je Seriennummer: [Serial => ['id', 'zeit', 'text', 'typ', 'bild']]
     */
    private function AlarmeLaden(array $Seriennummern): array
    {
        $Result = $this->Anfrage('GET', EZVIZ::MELDUNGEN, [
            'serials' => implode(',', $Seriennummern),
            'stype'   => EZVIZ::MELDUNGEN_TYP,
            'limit'   => 20,
            'endTime' => ''
        ]);
        if (!$Result['Success'] || !is_array($Result['Data'])) {
            $this->SendDebug('Alarme', 'konnten nicht geladen werden', 0);
            return [];
        }
        $Meldungen = $Result['Data']['message'] ?? $Result['Data']['messages'] ?? [];

        $Alarme = [];
        foreach ($Meldungen as $M) {
            if (!is_array($M)) {
                continue;
            }
            $Serial = (string) ($M['deviceSerial'] ?? '');
            if ($Serial === '' || isset($Alarme[$Serial])) {
                continue;
            }
            $Ext = is_array($M['ext'] ?? null) ? $M['ext'] : [];
            $Bild = (string) ($M['pic'] ?? '');
            if ($Bild === '' && !empty($Ext['pics']) && is_string($Ext['pics'])) {
                $Bild = (string) (array_values(array_filter(explode(';', $Ext['pics'])))[0] ?? '');
            }
            $Alarme[$Serial] = [
                'id'   => (string) ($M['msgId'] ?? ''),
                'zeit' => $this->AlarmZeit($M['time'] ?? null, (string) ($M['timeStr'] ?? $Ext['alarmStartTime'] ?? '')),
                'text' => (string) ($M['title'] ?? $M['detail'] ?? $M['sampleName'] ?? ''),
                'typ'  => (string) ($Ext['alarmType'] ?? $M['subType'] ?? ''),
                'bild' => $Bild
            ];
        }
        return $Alarme;
    }

    /**
     * Zeitstempel einer Meldung. Bevorzugt die Epoch-Zeit; weicht sie stark vom
     * Klartext ab, liefert die Kamera Ortszeit – dann gilt der Klartext.
     */
    private function AlarmZeit($Epoch, string $Text): int
    {
        $Zeit = 0;
        if (is_numeric($Epoch)) {
            $Zeit = (float) $Epoch > 100000000000 ? (int) round((float) $Epoch / 1000) : (int) $Epoch;
        }
        if ($Text !== '') {
            $Text = str_replace('Today', date('Y-m-d'), $Text);
            $Klartext = strtotime($Text);
            if ($Klartext !== false && ($Zeit === 0 || abs($Zeit - $Klartext) > 120)) {
                $Zeit = $Klartext;
            }
        }
        return $Zeit;
    }

    /**
     * Merkt sich den letzten Fehler (Anzeige in der Instanz) und meldet ihn einmal im Meldungsfenster.
     */
    private function FehlerMerken(string $Text): void
    {
        $Text = trim($Text);
        if ($Text !== preg_replace('/^\d\d:\d\d:\d\d /', '', $this->ReadAttributeString('LetzterFehler'))) {
            $this->LogMessage('EZVIZ-Abruf fehlgeschlagen: ' . $Text, KL_WARNING);
        }
        $this->WriteAttributeString('LetzterFehler', date('H:i:s') . ' ' . $Text);
    }

    private function CacheLesen(): array
    {
        $Cache = json_decode($this->ReadAttributeString('Cache'), true);
        return is_array($Cache) ? $Cache : [];
    }

    private function IntervallSetzen(): void
    {
        $Sekunden = $this->ReadPropertyInteger('Intervall');
        if ($Sekunden > 0) {
            $Sekunden = max(15, $Sekunden);
        }
        $this->SetTimerInterval('Aktualisieren', $this->Pruefen(false) ? $Sekunden * 1000 : 0);
    }

    // ------------------------------------------------------------------
    // Anmeldung und Sitzung
    // ------------------------------------------------------------------

    /**
     * Prüft Hinweis, Aktiv-Schalter und Zugangsdaten und setzt den Status.
     */
    private function Pruefen(bool $StatusSetzen = true): bool
    {
        $Status = 0;
        if (!$this->ReadPropertyBoolean('HinweisBestaetigt')) {
            $Status = EZVIZ::STATUS_HINWEIS;
        } elseif (!$this->ReadPropertyBoolean('Aktiv')) {
            $Status = IS_INACTIVE;
        } elseif ($this->ReadPropertyString('EMail') === '' || $this->ReadPropertyString('Passwort') === '') {
            $Status = EZVIZ::STATUS_ZUGANG_FEHLT;
        }
        if ($Status !== 0) {
            if ($StatusSetzen && $this->GetStatus() != $Status) {
                $this->SetStatus($Status);
            }
            return false;
        }
        return true;
    }

    /**
     * Stellt eine Sitzung her. Automatische Abrufe versuchen nach falschen
     * Zugangsdaten oder fehlendem Bestätigungscode keine neue Anmeldung –
     * sonst würde EZVIZ das Konto sperren bzw. ständig neue Codes schicken.
     */
    private function Verbinden(bool $Automatisch = false, string $Abgelaufen = ''): bool
    {
        if ($Automatisch && in_array($this->GetStatus(), [EZVIZ::STATUS_LOGIN_FEHLER, EZVIZ::STATUS_CODE_NOETIG], true)) {
            return false;
        }
        $Sitzung = $this->ReadAttributeString('SessionId');
        if ($Sitzung !== '' && $Sitzung !== $Abgelaufen) {
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return true;
        }
        if ($Automatisch && $this->ReadAttributeInteger('WartenBis') > time()) {
            return false;
        }
        // Nur ein Ablauf erneuert die Sitzung – sonst verwirft der zweite die frische Sitzung des ersten
        // und erzwingt eine neue Anmeldung (im schlimmsten Fall mit Bestätigungscode)
        return $this->Exklusiv(function () use ($Abgelaufen): bool {
            $Sitzung = $this->ReadAttributeString('SessionId');
            if ($Sitzung !== '' && $Sitzung !== $Abgelaufen) {
                return true; // inzwischen von einem anderen Ablauf erneuert
            }
            if ($Sitzung !== '') {
                $this->WriteAttributeString('SessionId', '');
            }
            if ($this->ReadAttributeString('RefreshId') !== '') {
                $Erneuert = $this->SitzungErneuern();
                if ($Erneuert !== false) {
                    return (bool) $Erneuert; // erneuert oder Netzstörung (dann Erneuerungsschlüssel behalten)
                }
            }
            return $this->Anmelden();
        });
    }

    /**
     * Führt eine Anmeldung bzw. Sitzungserneuerung exklusiv aus (Semaphore je Konto).
     */
    private function Exklusiv(callable $Aufgabe): bool
    {
        $Name = 'EZVIZ_Sitzung_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($Name, 30000)) {
            $this->SendDebug('Sitzung', 'Anmeldung läuft noch in einem anderen Ablauf – übersprungen', 0);
            return false;
        }
        try {
            return (bool) $Aufgabe();
        } finally {
            IPS_SemaphoreLeave($Name);
        }
    }

    /**
     * Vorübergehende Ablehnung (zu viele Anfragen, Konto kurz gesperrt, unbekannter Fehler):
     * nicht im normalen Takt weiter probieren. Wartezeit verdoppelt sich: 5 Minuten, 10, 20 … bis höchstens 6 Stunden.
     * „Übernehmen“ oder „Verbindung testen“ starten sofort einen neuen Versuch.
     */
    private function WartezeitSetzen(): int
    {
        $Anzahl = $this->ReadAttributeInteger('Fehlversuche') + 1;
        $this->WriteAttributeInteger('Fehlversuche', $Anzahl);
        $Sekunden = (int) min(21600, 300 * 2 ** min(10, $Anzahl - 1));
        $this->WriteAttributeInteger('WartenBis', time() + $Sekunden);
        $this->SendDebug('Anmelden', sprintf('%d. Ablehnung – nächster Versuch in %d Minuten', $Anzahl, $Sekunden / 60), 0);
        return $Sekunden;
    }

    private function WartezeitZuruecksetzen(): void
    {
        if ($this->ReadAttributeInteger('Fehlversuche') !== 0 || $this->ReadAttributeInteger('WartenBis') !== 0) {
            $this->WriteAttributeInteger('Fehlversuche', 0);
            $this->WriteAttributeInteger('WartenBis', 0);
        }
    }

    private function Anmelden(int $Versuch = 0): bool
    {
        $Code = trim($this->ReadPropertyString('Code'));
        $MitCode = ($Code !== '' && $Code !== $this->ReadAttributeString('CodeVerwendet'));

        $Form = [
            'account'     => $this->ReadPropertyString('EMail'),
            'password'    => md5($this->ReadPropertyString('Passwort')),
            'featureCode' => $this->ReadAttributeString('FeatureCode'),
            'msgType'     => $MitCode ? '3' : '0',
            'bizType'     => $MitCode ? 'TERMINAL_BIND' : '',
            'cuName'      => EZVIZ::KENNUNG
        ];
        if ($MitCode) {
            $Form['smsCode'] = $Code;
        }
        if ($this->ReadPropertyBoolean('Push')) {
            $Form['pushRegisterJson'] = EZVIZ::PUSH_REGISTER_JSON;
            $Form['pushExtJson'] = EZVIZ::PUSH_EXT_JSON;
        }

        $this->SendDebug('Anmelden', $this->ReadPropertyString('EMail') . ' @ ' . $this->ApiDomain() . ($MitCode ? ' (mit Bestätigungscode)' : ''), 0);
        $Result = $this->Http('POST', 'https://' . $this->ApiDomain() . EZVIZ::LOGIN, [], $Form);
        $D = $Result['Data'];
        $ApiCode = EZVIZ::ApiCode($D);

        if ($Result['Code'] == 200 && $ApiCode === 200 && isset($D['loginSession']['sessionId'])) {
            $this->WriteAttributeString('SessionId', (string) $D['loginSession']['sessionId']);
            $this->WriteAttributeString('RefreshId', (string) ($D['loginSession']['rfSessionId'] ?? ''));
            $this->WriteAttributeString('Benutzer', (string) ($D['loginUser']['username'] ?? ''));
            if (!empty($D['loginArea']['apiDomain'])) {
                $this->WriteAttributeString('ApiDomain', (string) $D['loginArea']['apiDomain']);
            }
            $this->WriteAttributeString('LoginHash', $this->LoginHash());
            $this->WriteAttributeString('UserId', (string) ($D['loginUser']['userId'] ?? ''));
            if ($MitCode) {
                $this->WriteAttributeString('CodeVerwendet', $Code);
            }
            $this->WartezeitZuruecksetzen();
            $this->SetStatus(IS_ACTIVE);
            return true;
        }

        $this->SitzungLoeschen();

        switch ($ApiCode) {
            case 1100: // falsche Region – Server aus der Antwort übernehmen
                if ($Versuch < 2 && !empty($D['loginArea']['apiDomain'])) {
                    $this->WriteAttributeString('ApiDomain', (string) $D['loginArea']['apiDomain']);
                    return $this->Anmelden($Versuch + 1);
                }
                $Text = 'Falscher Server/Region';
                break;
            case 6002: // Zwei-Faktor-Anmeldung: Code anfordern
                $this->CodeAnfordern();
                $this->SetStatus(EZVIZ::STATUS_CODE_NOETIG);
                $this->LogMessage('EZVIZ verlangt einen Bestätigungscode. Er wurde per E-Mail/SMS angefordert – bitte im Konto eintragen und übernehmen.', KL_WARNING);
                return false;
            case 1012:
                $Text = 'Bestätigungscode ungültig oder abgelaufen – Feld leeren, übernehmen und den neuen Code eintragen';
                break;
            case 1013:
                $Text = 'Benutzername unbekannt';
                break;
            case 1014:
                $Text = 'Passwort falsch';
                break;
            default:
                if ($ApiCode !== 1015 && ($Result['Code'] == 0 || $Result['Code'] >= 500)) {
                    // Netz- oder Serverstörung: beim nächsten Abruf erneut versuchen
                    $this->SetStatus(EZVIZ::STATUS_KEINE_VERBINDUNG);
                    $this->SendDebug('Anmelden', 'Server nicht erreichbar: ' . $Result['Error'], 0);
                    return false;
                }
                // Vorübergehende Ablehnung (HTTP 429, Konto kurz gesperrt, unbekannter Code):
                // kein dauerhafter Stopp, aber Wartezeit mit steigendem Abstand
                $Text = $ApiCode === 1015
                    ? 'Konto vorübergehend gesperrt (zu viele Versuche)'
                    : 'HTTP ' . $Result['Code'] . ', Code ' . $ApiCode . ' ' . (string) ($D['meta']['message'] ?? $Result['Error']);
                $Minuten = (int) round($this->WartezeitSetzen() / 60);
                if ($this->GetStatus() != EZVIZ::STATUS_WARTEN) {
                    $this->SetStatus(EZVIZ::STATUS_WARTEN);
                }
                $this->LogMessage('Anmeldung bei EZVIZ vorübergehend abgelehnt: ' . trim($Text) . ' – neuer Versuch in ' . $Minuten . ' min', KL_WARNING);
                return false;
        }
        $this->SetStatus(EZVIZ::STATUS_LOGIN_FEHLER);
        $this->LogMessage('Anmeldung bei EZVIZ fehlgeschlagen: ' . $Text, KL_ERROR);
        return false;
    }

    private function CodeAnfordern(): void
    {
        $Result = $this->Http('POST', 'https://' . $this->ApiDomain() . EZVIZ::CODE_ANFORDERN, [], [
            'from'    => $this->ReadPropertyString('EMail'),
            'bizType' => 'TERMINAL_BIND'
        ]);
        $this->SendDebug('Code anfordern', EZVIZ::ApiOk($Result['Data']) ? 'angefordert' : 'fehlgeschlagen', 0);
    }

    /**
     * true = erneuert, false = abgelehnt (neue Anmeldung nötig), null = Netz- oder Serverstörung.
     */
    private function SitzungErneuern(): ?bool
    {
        $Result = $this->Http('PUT', 'https://' . $this->ApiDomain() . EZVIZ::SESSION_ERNEUERN, [], [
            'refreshSessionId' => $this->ReadAttributeString('RefreshId'),
            'featureCode'      => $this->ReadAttributeString('FeatureCode')
        ] + ($this->ReadPropertyBoolean('Push') ? ['pushRegisterJson' => EZVIZ::PUSH_REGISTER_JSON, 'pushExtJson' => EZVIZ::PUSH_EXT_JSON] : []));
        $D = $Result['Data'];
        if ($Result['Code'] == 200 && EZVIZ::ApiOk($D) && isset($D['sessionInfo']['sessionId'])) {
            $this->WriteAttributeString('SessionId', (string) $D['sessionInfo']['sessionId']);
            $this->WriteAttributeString('RefreshId', (string) ($D['sessionInfo']['refreshSessionId'] ?? $this->ReadAttributeString('RefreshId')));
            $this->SendDebug('Sitzung', 'erneuert', 0);
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return true;
        }
        if ($Result['Code'] == 0 || $Result['Code'] == 429 || $Result['Code'] >= 500) {
            // Störung, keine Ablehnung: Erneuerungsschlüssel behalten und beim nächsten Abruf erneut versuchen
            $this->SendDebug('Sitzung', 'Erneuern nicht möglich (' . $Result['Error'] . ') – neuer Versuch beim nächsten Abruf', 0);
            return null;
        }
        $this->SendDebug('Sitzung', 'Erneuern fehlgeschlagen, neue Anmeldung nötig', 0);
        $this->SitzungLoeschen();
        return false;
    }

    private function SitzungLoeschen(): void
    {
        $this->WriteAttributeString('SessionId', '');
        $this->WriteAttributeString('RefreshId', '');
    }

    private function ApiDomain(): string
    {
        $Domain = $this->ReadAttributeString('ApiDomain');
        if ($Domain === '') {
            $Domain = trim($this->ReadPropertyString('Server'));
            $Domain = preg_replace('#^https?://#', '', $Domain);
            $Domain = rtrim((string) $Domain, '/');
        }
        return $Domain !== '' ? $Domain : EZVIZ::SERVER_STANDARD;
    }

    /**
     * Anfrage mit automatischer Anmeldung und einem Wiederholungsversuch bei abgelaufener Sitzung.
     */
    private function Anfrage(string $Method, string $Path, array $Query = [], array $Form = []): array
    {
        if (!$this->Pruefen()) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Konto ist nicht aktiv'];
        }
        if (!$this->Verbinden(true)) {
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => 'Nicht angemeldet – Konto-Instanz prüfen'];
        }
        // Mit dieser Sitzung wird angefragt – läuft sie ab, wird genau sie ersetzt
        $Abgelaufen = $this->ReadAttributeString('SessionId');

        $Result = $this->Http($Method, 'https://' . $this->ApiDomain() . $Path, $Query, $Form);
        $ApiCode = EZVIZ::ApiCode($Result['Data']);
        // Abgelaufene Sitzung: EZVIZ meldet das nicht immer mit 401 – bei der Geräteliste
        // gilt deshalb jeder Fehlercode als Anlass, die Sitzung zu erneuern (wie die EZVIZ-App)
        $Sitzung = $Result['Code'] == 401 || $ApiCode === 401 || $ApiCode === 403
            || ($Path === EZVIZ::GERAETELISTE && $Result['Code'] == 200 && $ApiCode !== 200);
        if ($Sitzung) {
            $this->SendDebug('Anfrage', 'Sitzung abgelaufen – erneuere', 0);
            if ($this->Verbinden(true, $Abgelaufen)) {
                $Result = $this->Http($Method, 'https://' . $this->ApiDomain() . $Path, $Query, $Form);
            }
        }

        $Ok = $Result['Code'] >= 200 && $Result['Code'] < 300 && EZVIZ::ApiOk($Result['Data']);
        $Result['Success'] = $Ok;
        if (!$Ok && $Result['Error'] === '') {
            $D = $Result['Data'];
            // EZVIZ antwortet teils auf Chinesisch – nur lateinische Texte übernehmen
            $Meldung = (string) ($D['meta']['message'] ?? $D['resultDes'] ?? '');
            if (preg_match('/[^\x{0000}-\x{024F}\x{2000}-\x{206F}]/u', $Meldung)) {
                $Meldung = '';
            }
            $Result['Error'] = trim('Code ' . EZVIZ::ApiCode($D) . ' ' . $Meldung);
        }
        return $Result;
    }

    private function Http(string $Method, string $Url, array $Query, array $Form): array
    {
        $Header = [
            'Accept: application/json',
            'featureCode: ' . $this->ReadAttributeString('FeatureCode'),
            'clientType: 3',
            'osVersion: ' . ($this->ReadPropertyBoolean('Push') ? '13' : ''),
            'clientVersion: ' . ($this->ReadPropertyBoolean('Push') ? '7.4.1.0421' : ''),
            'netType: WIFI',
            'customno: 1000001',
            'ssid: ',
            'clientNo: ' . ($this->ReadPropertyBoolean('Push') ? 'google' : 'web_site'),
            'appId: ys7',
            'language: de_DE',
            'lang: de',
            'sessionId: ' . $this->ReadAttributeString('SessionId'),
            'User-Agent: okhttp/3.12.1'
        ];

        if (count($Query)) {
            $Url .= (strpos($Url, '?') === false ? '?' : '&') . http_build_query($Query);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $Method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if ($Method !== 'GET') {
            $Header[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($Form));
        }
        curl_setopt($ch, CURLOPT_URL, $Url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $Header);

        $Geheim = (strpos($Url, EZVIZ::LOGIN) !== false || strpos($Url, EZVIZ::SESSION_ERNEUERN) !== false);
        $this->SendDebug('Anfrage', $Method . ' ' . $Url, 0);
        if (!$Geheim && count($Form)) {
            $this->SendDebug('Daten', json_encode($Form), 0);
        }

        $Antwort = curl_exec($ch);
        $Code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $Fehler = curl_error($ch);
        curl_close($ch);

        if ($Antwort === false) {
            $this->SendDebug('Fehler', $Fehler, 0);
            return ['Success' => false, 'Code' => 0, 'Data' => null, 'Error' => $Fehler];
        }

        $this->SendDebug('Antwort ' . $Code, $Geheim ? '(Sitzungsdaten ausgeblendet)' : $Antwort, 0);

        $Daten = null;
        if ($Antwort !== '') {
            $Daten = json_decode($Antwort, true);
            if ($Daten === null) {
                $Daten = $Antwort;
            }
        }
        $Ok = ($Code >= 200 && $Code < 300);
        return [
            'Success' => $Ok,
            'Code'    => $Code,
            'Data'    => $Daten,
            'Error'   => $Ok ? '' : 'HTTP ' . $Code
        ];
    }
}
