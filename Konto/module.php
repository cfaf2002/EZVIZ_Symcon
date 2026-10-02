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

        $this->RegisterAttributeString('FeatureCode', '');
        $this->RegisterAttributeString('SessionId', '');
        $this->RegisterAttributeString('RefreshId', '');
        $this->RegisterAttributeString('ApiDomain', '');
        $this->RegisterAttributeString('Benutzer', '');
        $this->RegisterAttributeString('LoginHash', '');
        $this->RegisterAttributeString('CodeVerwendet', '');
        $this->RegisterAttributeString('Cache', '{}');
        $this->RegisterAttributeInteger('Stand', 0);

        $this->RegisterTimer('Aktualisieren', 0, 'EZVIZ_RefreshAll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('Aktualisieren', 0);

        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if ($this->ReadAttributeString('FeatureCode') === '') {
            $this->WriteAttributeString('FeatureCode', md5(uniqid('symcon', true) . random_bytes(8)));
        }

        if (!$this->Pruefen()) {
            return;
        }

        // Zugangsdaten oder Server geändert? Dann alte Sitzung verwerfen.
        $Hash = md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort') . '|' . $this->ReadPropertyString('Server'));
        if ($Hash !== $this->ReadAttributeString('LoginHash')) {
            $this->SitzungLoeschen();
            $this->WriteAttributeString('ApiDomain', '');
        }

        $this->IntervallSetzen();
        if ($this->Verbinden()) {
            $this->Aktualisieren();
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
        $this->SitzungLoeschen();
        $Ok = $this->Anmelden();
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
                    ? ['Success' => true, 'Code' => 200, 'Data' => $Cache[$Serial], 'Error' => '']
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

        foreach ($Geraete as $Serial => $Geraet) {
            $this->SendDataToChildren(json_encode([
                'DataID' => EZVIZ::DATA_FROM_KONTO,
                'Serial' => (string) $Serial,
                'Typ'    => 'Status',
                'Daten'  => $Geraet
            ]));
        }
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
    private function Verbinden(bool $Automatisch = false): bool
    {
        if ($Automatisch && in_array($this->GetStatus(), [EZVIZ::STATUS_LOGIN_FEHLER, EZVIZ::STATUS_CODE_NOETIG], true)) {
            return false;
        }
        if ($this->ReadAttributeString('SessionId') !== '') {
            if ($this->GetStatus() != IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return true;
        }
        if ($this->ReadAttributeString('RefreshId') !== '' && $this->SitzungErneuern()) {
            return true;
        }
        return $this->Anmelden();
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
            $this->WriteAttributeString('LoginHash', md5($this->ReadPropertyString('EMail') . '|' . $this->ReadPropertyString('Passwort') . '|' . $this->ReadPropertyString('Server')));
            if ($MitCode) {
                $this->WriteAttributeString('CodeVerwendet', $Code);
            }
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
            case 1015:
                $Text = 'Konto vorübergehend gesperrt (zu viele Versuche)';
                break;
            default:
                if ($Result['Code'] == 0 || $Result['Code'] >= 500) {
                    // Netz- oder Serverstörung: beim nächsten Abruf erneut versuchen
                    $this->SetStatus(EZVIZ::STATUS_KEINE_VERBINDUNG);
                    $this->SendDebug('Anmelden', 'Server nicht erreichbar: ' . $Result['Error'], 0);
                    return false;
                }
                $Text = 'HTTP ' . $Result['Code'] . ', Code ' . $ApiCode . ' ' . (string) ($D['meta']['message'] ?? $Result['Error']);
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

    private function SitzungErneuern(): bool
    {
        $Result = $this->Http('PUT', 'https://' . $this->ApiDomain() . EZVIZ::SESSION_ERNEUERN, [], [
            'refreshSessionId' => $this->ReadAttributeString('RefreshId'),
            'featureCode'      => $this->ReadAttributeString('FeatureCode')
        ]);
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

        $Result = $this->Http($Method, 'https://' . $this->ApiDomain() . $Path, $Query, $Form);
        $ApiCode = EZVIZ::ApiCode($Result['Data']);
        if ($Result['Code'] == 401 || $ApiCode === 401 || $ApiCode === 403) {
            $this->SendDebug('Anfrage', 'Sitzung abgelaufen – erneuere', 0);
            $this->WriteAttributeString('SessionId', '');
            $Ok = ($this->ReadAttributeString('RefreshId') !== '' && $this->SitzungErneuern()) || $this->Verbinden(true);
            if ($Ok) {
                $Result = $this->Http($Method, 'https://' . $this->ApiDomain() . $Path, $Query, $Form);
            }
        }

        $Ok = $Result['Code'] >= 200 && $Result['Code'] < 300 && EZVIZ::ApiOk($Result['Data']);
        $Result['Success'] = $Ok;
        if (!$Ok && $Result['Error'] === '') {
            $D = $Result['Data'];
            $Result['Error'] = 'Code ' . EZVIZ::ApiCode($D) . ' ' . (string) ($D['meta']['message'] ?? $D['resultDes'] ?? '');
        }
        return $Result;
    }

    private function Http(string $Method, string $Url, array $Query, array $Form): array
    {
        $Header = [
            'Accept: application/json',
            'featureCode: ' . $this->ReadAttributeString('FeatureCode'),
            'clientType: 3',
            'osVersion: ',
            'clientVersion: ',
            'netType: WIFI',
            'customno: 1000001',
            'ssid: ',
            'clientNo: web_site',
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
