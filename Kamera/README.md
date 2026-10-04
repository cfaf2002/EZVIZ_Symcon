# EZVIZ Kamera

Eine EZVIZ-Kamera als Instanz in IP-Symcon.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Seriennummer | Wird vom Konfigurator gesetzt |
| Bemerkung | Freies Textfeld für eigene Notizen zur Kamera (nur in der Instanz sichtbar) |
| Verifizierungscode | 6 Großbuchstaben vom Aufkleber der Kamera – RTSP-Passwort und Schlüssel für verschlüsselte Alarmbilder |
| Livestream | Legt ein Medienobjekt mit dem RTSP-Stream an |
| Eigene Kachel verwenden | Kachel mit Standbild, Status, Schwenk-Pfeilen und Knöpfen |
| Standbild regelmäßig aktualisieren | Holt regelmäßig ein neues Bild (Standard alle 30 Sekunden) |
| Bilder holen | **Nur bei geöffneter Kachel** (Standard): beim Öffnen sofort ein frisches Bild, dann regelmäßig, solange die Kachel offen ist – sonst keine Abrufe. **Immer**: auch im Hintergrund, das Medienobjekt ist stets aktuell (z. B. für Skripte) |
| Zusätzlich im Hintergrund alle … Minuten | Nur bei „Nur bei geöffneter Kachel“: Netz-Kameras holen auch ohne geöffnete Kachel regelmäßig ein Bild (Standard 10 Minuten, 0 = aus). Beim Öffnen ist dann sofort ein recht aktuelles Bild da. Akku-Kameras nicht |
| Akku-Kameras: solange die Kachel geöffnet ist | Standard an: Akku-Kameras holen alle 30 s ein neues Bild, aber nur während jemand die Kachel ansieht |
| Akku-Kameras: auch im Hintergrund | Standard aus: regelmäßig auch ohne geöffnete Kachel – leert den Akku schnell |
| Bildqualität (lokal) | Hoch (Standard): volle Auflösung aus dem Hauptstream. Schnell: wie der Livestream, geringere Auflösung |
| Quelle | Automatisch: lokal per FFmpeg aus dem Stream, wenn FFmpeg installiert ist, sonst über die Cloud. Klappt lokal nicht, wird 10 Minuten lang direkt die Cloud genutzt |
| Pfad zu FFmpeg | Leer = automatisch suchen (z. B. /usr/bin/ffmpeg) |
| Stream | Unterstream (Standard, lädt schnell), Hauptstream (volle Auflösung), Standard `/H.264` oder eigener Pfad |
| Benutzer / IP / Port | Standard `admin`, IP automatisch aus der Cloud, Port 554 |
| Bild des letzten Alarms laden | Lädt das Alarmbild in ein Medienobjekt |
| „Bewegung erkannt“ zurücksetzen nach | Sekunden, nach denen die Variable wieder auf Aus geht (Standard 60) |
| Benachrichtigungen → Visualisierung | Kachel-Visualisierung, an die Meldungen gehen (erscheinen dort und als Push auf den Handys) |
| Bei Bewegung eine Meldung senden | Meldung „Bewegung: Kamera“ bei jedem neuen Alarm, höchstens alle X Sekunden (Standard 120) |
| Bei schwachem Akku eine Meldung senden / Akku schwach ab | Nur Akku-Kameras, Grenze in % (Standard 20) |
| Nach einem Alarm länger wach halten | Nur Akku-Kameras: hält die Kamera nach einer Bewegung wach und holt ein frisches Standbild (am besten mit Push im Konto) |
| Arbeitsmodus steuern | Nur Akku-Kameras: Variable „Arbeitsmodus“ und Befehl EZVIZ_SetWorkMode; ausgeschaltet bleibt der Modus unverändert |
| Licht steuern | Nur Licht-Kameras (z. B. LC3): Variable „Licht“, Regler „Helligkeit“ und Knopf „Licht“ in der Kachel |
| Dauer je Schwenkschritt | Wie lange die Kamera pro Tastendruck schwenkt (Standard 500 ms) |

## Schneller Bildaufbau

- **Standbild** statt Livestream für den schnellen Blick: Es wird beim Öffnen der Kachel sofort geholt (oder bei „Immer“ im Hintergrund). Bei einem neuen Alarm und nach dem Schwenken wird es sofort erneuert, im Schlafmodus nicht.
- **Lokal per FFmpeg** ist am schnellsten und belastet die Cloud nicht. Unter Linux/Raspberry Pi: `sudo apt install ffmpeg`. Auf der Synology das Paket **ffmpeg7** der SynoCommunity installieren (Paket-Zentrum → Einstellungen → Paketquellen: `https://packages.synocommunity.com`) – das Modul findet es automatisch. Läuft Symcon im Docker-Container: die Datei `ffmpeg` (statische Version von johnvansickle.com/ffmpeg, passend zum Prozessor) per File Station in den Ordner legen, den der Container als Symcon-Datenordner eingebunden hat – das Modul findet sie dort automatisch und macht sie ausführbar. Auf der SymBox ist FFmpeg meist nicht verfügbar – dann wird die Cloud genutzt (die Kamera macht dafür ein Foto).
- **Unterstream** für den Livestream: geringere Auflösung, startet deutlich schneller.
- Langsam bleibt der Livestream, wenn die Kamera H.265 sendet – dann in der EZVIZ-App (falls angeboten) auf H.264 umstellen.

Kommt kein Livebild, die Adresse zuerst mit VLC testen – sie steht im Button „Stream-Adresse anzeigen“.

## Kachel

- Passt sich automatisch an das Design der Kachel-Visualisierung an (Farben, Hell/Dunkel)
- Knopf **„Live“** unten rechts im Bild öffnet den Livestream groß (nur wenn „Livestream als Medienobjekt anlegen“ an ist)

- Zeigt das neueste Standbild (ohne Standbild das letzte Alarmbild) mit Alter, Online-Punkt und „Bewegung“-Hinweis
- Pfeile im Bild schwenken die Kamera, danach kommt gleich ein neues Bild
- Knöpfe: Bild neu holen, Bewegungserkennung, Schlafmodus
- Akku-Kameras: Akkustand oben im Bild (grün, ab 40 % gelb, unter der Grenze rot) und bei schwachem Akku ein roter Hinweis unter dem Bild

## Akku-Warnung

Erreicht der Akku die eingestellte Grenze, kommt **eine** Meldung: in der Visualisierung, als Push auf den angemeldeten Handys und im Meldungsfenster. Eine neue Meldung gibt es erst, nachdem der Akku wieder geladen wurde (5 % über der Grenze). Mit „Meldung testen“ (unter „Fehlersuche“) lässt sich prüfen, ob Meldungen ankommen.

## Variablen

| Variable | Beschreibung |
| :------- | :----------- |
| Online | Kamera ist mit der Cloud verbunden |
| Bewegungserkennung | Schaltbar: Alarm-Benachrichtigung ein/aus |
| Bewegung erkannt | Geht bei jedem neuen Alarm für die eingestellte Dauer auf An |
| Letzter Alarm / Alarmart | Zeitpunkt und Art des neuesten Alarms |
| Schalter | Nur die, die die Kamera meldet: Schlafmodus (Privatsphäre), Objektiv abdecken, Statusleuchte, Infrarot-Nachtsicht, Tonaufnahme, Bewegungsverfolgung, Alarmton, Alarmlicht |
| Schwenken | Links / Rechts / Hoch / Runter – nur bei Schwenk-/Neigekameras (z. B. C8C) |
| Licht / Helligkeit | Nur mit „Licht steuern“: Licht an/aus und Helligkeit 1–100 % |
| Arbeitsmodus | Nur mit „Arbeitsmodus steuern“: Energiesparen / Hochleistung / Netzbetrieb / Super-Energiesparen |
| Akku / Akku schwach | Nur bei Akku-Kameras: Akkustand und ob die Grenze erreicht ist |
| WLAN-Signal | Nur wenn die Kamera den Wert liefert |
| Firmware / Firmware-Update verfügbar | Firmwarestand |
| Aktualisieren / Letzte Aktualisierung | Sofortige Abfrage und Zeitpunkt des letzten Abrufs |

Medienobjekte: **Standbild**, **Livestream** (RTSP) und **Alarmbild**.

## PHP-Befehle

```php
bool   EZVIZ_Update(int $InstanzID);                              // Sofort neu abfragen
bool   EZVIZ_UpdateSnapshot(int $InstanzID);                      // Sofort neues Standbild holen
string EZVIZ_GetSnapshotStatus(int $InstanzID);                   // Ergebnis/Fehlergrund des letzten Versuchs
bool   EZVIZ_TestNotification(int $InstanzID);                    // Test-Meldung an die Visualisierung
bool   EZVIZ_SetWorkMode(int $InstanzID, int $Modus);             // 0 Energiesparen, 1 Hochleistung, 2 Netzbetrieb, 3 Super-Energiesparen
bool   EZVIZ_SetLight(int $InstanzID, bool $An);                  // Licht an/aus (Licht-Kameras)
bool   EZVIZ_SetBrightness(int $InstanzID, int $Prozent);         // Helligkeit 1–100 %
bool   EZVIZ_SetMotionDetection(int $InstanzID, bool $Aktiv);     // Bewegungserkennung ein/aus
bool   EZVIZ_SetSwitch(int $InstanzID, int $Typ, bool $Aktiv);    // z. B. 21 = Schlafmodus, 3 = Statusleuchte, 10 = Nachtsicht
bool   EZVIZ_Move(int $InstanzID, string $Richtung);              // 'left', 'right', 'up', 'down'
string EZVIZ_GetStreamUrl(int $InstanzID);                        // RTSP-Adresse mit Zugangsdaten
array  EZVIZ_GetData(int $InstanzID);                             // Rohdaten aus der Cloud (Fehlersuche)
```
