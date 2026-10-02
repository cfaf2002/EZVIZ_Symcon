# EZVIZ Kamera

Eine EZVIZ-Kamera als Instanz in IP-Symcon.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Seriennummer | Wird vom Konfigurator gesetzt |
| Verifizierungscode | 6 Großbuchstaben vom Aufkleber der Kamera – RTSP-Passwort und Schlüssel für verschlüsselte Alarmbilder |
| Livestream | Legt ein Medienobjekt mit dem RTSP-Stream an |
| Eigene Kachel verwenden | Kachel mit Standbild, Status, Schwenk-Pfeilen und Knöpfen |
| Standbild regelmäßig aktualisieren | Holt im Hintergrund ein Bild, das sofort angezeigt wird (Standard alle 30 Sekunden) |
| Quelle | Automatisch: lokal per FFmpeg aus dem Stream, wenn FFmpeg installiert ist, sonst über die Cloud. Klappt lokal nicht, wird 10 Minuten lang direkt die Cloud genutzt |
| Pfad zu FFmpeg | Leer = automatisch suchen (z. B. /usr/bin/ffmpeg) |
| Stream | Unterstream (Standard, lädt schnell), Hauptstream (volle Auflösung), Standard `/H.264` oder eigener Pfad |
| Benutzer / IP / Port | Standard `admin`, IP automatisch aus der Cloud, Port 554 |
| Bild des letzten Alarms laden | Lädt das Alarmbild in ein Medienobjekt |
| „Bewegung erkannt“ zurücksetzen nach | Sekunden, nach denen die Variable wieder auf Aus geht (Standard 60) |
| Dauer je Schwenkschritt | Wie lange die Kamera pro Tastendruck schwenkt (Standard 500 ms) |

## Schneller Bildaufbau

- **Standbild** statt Livestream für den schnellen Blick: Es wird im Hintergrund geholt und liegt fertig vor. Bei einem neuen Alarm und nach dem Schwenken wird es sofort erneuert, im Schlafmodus nicht.
- **Lokal per FFmpeg** ist am schnellsten und belastet die Cloud nicht. Unter Linux/Raspberry Pi: `sudo apt install ffmpeg`. Auf der Synology das Paket **ffmpeg7** der SynoCommunity installieren (Paket-Zentrum → Einstellungen → Paketquellen: `https://packages.synocommunity.com`) – das Modul findet es automatisch. Läuft Symcon im Docker-Container: die Datei `ffmpeg` (statische Version von johnvansickle.com/ffmpeg, passend zum Prozessor) per File Station in den Ordner legen, den der Container als Symcon-Datenordner eingebunden hat – das Modul findet sie dort automatisch und macht sie ausführbar. Auf der SymBox ist FFmpeg meist nicht verfügbar – dann wird die Cloud genutzt (die Kamera macht dafür ein Foto).
- **Unterstream** für den Livestream: geringere Auflösung, startet deutlich schneller.
- Langsam bleibt der Livestream, wenn die Kamera H.265 sendet – dann in der EZVIZ-App (falls angeboten) auf H.264 umstellen.

Kommt kein Livebild, die Adresse zuerst mit VLC testen – sie steht im Button „Stream-Adresse anzeigen“.

## Kachel

- Zeigt das neueste Standbild (ohne Standbild das letzte Alarmbild) mit Alter, Online-Punkt und „Bewegung“-Hinweis
- Pfeile im Bild schwenken die Kamera, danach kommt gleich ein neues Bild
- Knöpfe: Bild neu holen, Bewegungserkennung, Schlafmodus

## Variablen

| Variable | Beschreibung |
| :------- | :----------- |
| Online | Kamera ist mit der Cloud verbunden |
| Bewegungserkennung | Schaltbar: Alarm-Benachrichtigung ein/aus |
| Bewegung erkannt | Geht bei jedem neuen Alarm für die eingestellte Dauer auf An |
| Letzter Alarm / Alarmart | Zeitpunkt und Art des neuesten Alarms |
| Schalter | Nur die, die die Kamera meldet: Schlafmodus (Privatsphäre), Objektiv abdecken, Statusleuchte, Infrarot-Nachtsicht, Tonaufnahme, Bewegungsverfolgung, Alarmton, Alarmlicht |
| Schwenken | Links / Rechts / Hoch / Runter – nur bei Schwenk-/Neigekameras (z. B. C8C) |
| Akku / WLAN-Signal | Nur wenn die Kamera die Werte liefert |
| Firmware / Firmware-Update verfügbar | Firmwarestand |
| Aktualisieren / Letzte Aktualisierung | Sofortige Abfrage und Zeitpunkt des letzten Abrufs |

Medienobjekte: **Standbild**, **Livestream** (RTSP) und **Alarmbild**.

## PHP-Befehle

```php
bool   EZVIZ_Update(int $InstanzID);                              // Sofort neu abfragen
bool   EZVIZ_UpdateSnapshot(int $InstanzID);                      // Sofort neues Standbild holen
bool   EZVIZ_SetMotionDetection(int $InstanzID, bool $Aktiv);     // Bewegungserkennung ein/aus
bool   EZVIZ_SetSwitch(int $InstanzID, int $Typ, bool $Aktiv);    // z. B. 21 = Schlafmodus, 3 = Statusleuchte, 10 = Nachtsicht
bool   EZVIZ_Move(int $InstanzID, string $Richtung);              // 'left', 'right', 'up', 'down'
string EZVIZ_GetStreamUrl(int $InstanzID);                        // RTSP-Adresse mit Zugangsdaten
array  EZVIZ_GetData(int $InstanzID);                             // Rohdaten aus der Cloud (Fehlersuche)
```
