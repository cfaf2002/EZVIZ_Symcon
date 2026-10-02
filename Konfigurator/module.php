<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/EZVIZ.php';

/**
 * EZVIZ Konfigurator
 * Zeigt alle Geräte des EZVIZ-Kontos und legt Kameras als Instanzen an.
 *
 * Autor: Armin Frohwerk
 */
class EZVIZKonfigurator extends IPSModuleStrict
{
    // Gerätearten, die keine Kamera sind (Leuchten, Steckdosen) – werden nur angezeigt
    private const KEINE_KAMERA = ['lighting', 'Socket'];

    public function Create(): void
    {
        parent::Create();
    }

    /**
     * Übergeordnete Instanz: EZVIZ Konto
     */
    public function GetCompatibleParents(): string
    {
        return json_encode([
            'type'      => 'require',
            'moduleIDs' => [EZVIZ::MODUL_KONTO]
        ]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        if (!$this->HasActiveParent()) {
            $Form['actions'][0]['caption'] = 'Das Konto ist nicht verbunden. Bitte zuerst die Konto-Instanz einrichten.';
            $Form['actions'][0]['visible'] = true;
            return json_encode($Form);
        }

        $Result = EZVIZ::Response(@$this->SendDataToParent(EZVIZ::Request('Geraete')));
        $Geraete = ($Result['Success'] && is_array($Result['Data'])) ? $Result['Data'] : [];
        if (!$Result['Success']) {
            $Form['actions'][0]['caption'] = 'Geräte konnten nicht geladen werden: ' . $Result['Error'];
            $Form['actions'][0]['visible'] = true;
        } elseif (!count($Geraete)) {
            $Form['actions'][0]['caption'] = 'Noch keine Geräte geladen – im Konto „Jetzt abfragen“ drücken und den Konfigurator neu öffnen.';
            $Form['actions'][0]['visible'] = true;
        }

        // Vorhandene Kamera-Instanzen am selben Konto
        $Parent = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $Vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(EZVIZ::MODUL_KAMERA) as $ID) {
            if (IPS_GetInstance($ID)['ConnectionID'] == $Parent) {
                $Vorhanden[(string) IPS_GetProperty($ID, 'Serial')] = $ID;
            }
        }

        $Werte = [];
        foreach ($Geraete as $Serial => $G) {
            $Serial = (string) $Serial;
            $Zeile = [
                'name'       => (string) $G['name'],
                'serial'     => $Serial,
                'model'      => (string) $G['model'],
                'state'      => $G['online'] ? 'online' : 'offline',
                'instanceID' => $Vorhanden[$Serial] ?? 0
            ];
            if (!in_array($G['category'], self::KEINE_KAMERA, true)) {
                $Zeile['create'] = [
                    'moduleID'      => EZVIZ::MODUL_KAMERA,
                    'name'          => (string) $G['name'],
                    'configuration' => [
                        'Serial' => $Serial
                    ]
                ];
            }
            $Werte[] = $Zeile;
            unset($Vorhanden[$Serial]);
        }
        // Instanzen, deren Gerät es im Konto nicht mehr gibt
        foreach ($Vorhanden as $Serial => $ID) {
            $Werte[] = [
                'name'       => IPS_GetName($ID),
                'serial'     => (string) $Serial,
                'model'      => '',
                'state'      => 'nicht im Konto',
                'instanceID' => $ID
            ];
        }

        $Form['actions'][1]['values'] = $Werte;
        return json_encode($Form);
    }
}
