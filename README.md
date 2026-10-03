# EZVIZ für IP-Symcon

Bindet EZVIZ-Kameras (z. B. C8C) in IP-Symcon ein: Status, Bewegungserkennung, Schlafmodus und weitere Schalter über die EZVIZ-Cloud, Schwenken bei Schwenk-/Neigekameras, letzter Alarm mit Bild – und der Livestream lokal per RTSP.

> **Hinweis:** EZVIZ bietet für Endkunden keine offizielle Schnittstelle. Das Modul nutzt die Schnittstelle der EZVIZ-App, steht in keiner Verbindung zu EZVIZ bzw. Hikvision und ist nur für die private Nutzung gedacht. Ändert EZVIZ die Schnittstelle, kann das Modul ohne Vorwarnung aufhören zu funktionieren.

## Inhalt

| Modul | Typ | Aufgabe |
| :---- | :-- | :------ |
| [EZVIZ Konto](Konto/README.md) | I/O | Anmeldung am EZVIZ-Konto, Sitzung, regelmäßige Abfrage aller Geräte und Alarme |
| [EZVIZ Konfigurator](Konfigurator/README.md) | Konfigurator | Legt die Kameras des Kontos als Instanzen an |
| [EZVIZ Kamera](Kamera/README.md) | Gerät | Eine Kamera mit Variablen, Schaltern, Alarmbild und Livestream |
| [EZVIZ Push](Push/README.md) | Gerät | Sofort-Alarme über den Push-Kanal (wird vom Konto automatisch angelegt) |

## Voraussetzungen

- IP-Symcon ab Version 8.1
- EZVIZ-Konto mit E-Mail/Benutzername und Passwort
- Für den Livestream: Kamera im selben Netz wie Symcon, Verifizierungscode vom Aufkleber der Kamera

## Installation

1. Im Objektbaum unter *Kern Instanzen → Modules* die URL `https://github.com/cfaf2002/EZVIZ_Symcon` hinzufügen.
2. Instanz **EZVIZ Konfigurator** anlegen – die Konto-Instanz wird automatisch mit erstellt.
3. In der Konto-Instanz den Nutzungshinweis bestätigen, E-Mail und Passwort eintragen und übernehmen. Fordert EZVIZ einen Bestätigungscode an, diesen eintragen und erneut übernehmen.
4. Im Konfigurator die Kameras erstellen und in jeder Kamera den Verifizierungscode eintragen.

## Datenfluss

```
EZVIZ Kamera        ─┐
EZVIZ Kamera        ─┼──►  EZVIZ Konto  ──►  EZVIZ-Cloud
EZVIZ Konfigurator  ─┘
(Konto meldet neue Daten über die Variable „Letzter Abruf“, die Kameras holen sie dann selbst ab)
EZVIZ Push ── Client Socket ──► EZVIZ-Push-Server   (bei Alarm: Konto fragt sofort ab)
EZVIZ Kamera  ───── RTSP (lokal) ─────────►  Kamera
```

Das Konto fragt alle Geräte und die neuesten Alarme gemeinsam ab (eine Geräteliste + eine Meldungsliste pro Intervall). Die Kameras holen sich ihre Daten anschließend selbst beim Konto ab.

## GUIDs

| Modul | Präfix | GUID |
| :---- | :----: | :--: |
| EZVIZ Konto | EZVIZ | {7FD1051B-31F1-4C81-92B4-39D96ED1D346} |
| EZVIZ Konfigurator | EZVIZ | {D256E72C-D0FF-4536-97B6-FB6929356BA4} |
| EZVIZ Kamera | EZVIZ | {C4BCA507-8306-4DC3-B7B0-648BEED24442} |
| EZVIZ Push | EZVIZ | {B4259050-5300-47D8-9DAD-271C4AF28621} |

## Changelog

**Version 1.0 (Build 27)**
- Akku-Kameras: Standbild wird jetzt regelmäßig aktualisiert, solange die Kachel geöffnet ist (Schalter, standardmäßig an) – im Hintergrund weiterhin nur, wenn ausdrücklich eingeschaltet

**Version 1.0 (Build 26)**
- Fehlersuche: Button „Akku-Werte aus der Cloud anzeigen“ (Befehl EZVIZ_GetBatteryInfo)

**Version 1.0 (Build 25)**
- Fehler behoben: Nach einem Modul-Update übernahmen die Kameras keine neuen Daten mehr vom Konto (das Abo auf „Letzter Abruf“ wurde nach dem Neuladen nicht erneuert)
- Sicherheitsnetz: Jede Kamera prüft alle 5 Minuten, ob ihre Daten aktuell sind, und holt sie sonst selbst ab

**Version 1.0 (Build 24)**
- Fehler behoben: „Invalid Configuration“ in der Handy-App bei „Schwenken“, „Aktualisieren“ und „Arbeitsmodus“ (Feld „Color“ statt „ColorValue“ in den Auswahl-Optionen)

**Version 1.0 (Build 23)**
- Akku-Kameras: „Nach einem Alarm länger wach halten“ (Schalter je Kamera) – die Kamera bleibt nach einer Bewegung länger wach, das Modul holt sofort ein frisches Standbild
- Akku-Kameras: Arbeitsmodus (Energiesparen / Hochleistung / Netzbetrieb / Super-Energiesparen) als Variable und Befehl EZVIZ_SetWorkMode – nur aktiv, wenn in der Instanz eingeschaltet

**Version 1.0 (Build 22)**
- Push: Fehler behoben – Daten an die Push-Verbindung wurden falsch kodiert (Symcon erwartet bei diesem Modultyp HEX statt UTF-8), der Server trennte deshalb sofort („End of file“)

**Version 1.0 (Build 21)**
- Neu: Sofort-Alarme per Push (Schalter im Konto) – eigene Instanz „EZVIZ Push“ wird automatisch angelegt
- Neu: Bewegungsmeldung an die Visualisierung (Schalter je Kamera, mit Mindestabstand zwischen Meldungen)
- Neu: Lichtsteuerung für Licht-Kameras wie LC3 (Schalter je Kamera): Variable „Licht“, Regler „Helligkeit“, Knopf in der Kachel
- Fehler behoben: „Output-Buffer exceeds Limit“ in der Kachel – Bilder werden für die Kachel verkleinert, das Medienobjekt behält die volle Auflösung
- Aufgeräumt: Fehlersuch-Knöpfe im eingeklappten Bereich „Fehlersuche“, Benachrichtigungen in einem Bereich zusammengefasst, „Meldung testen“ statt „Akku-Meldung testen“, alter Code entfernt

**Version 1.0 (Build 20)**
- Bemerkung ist jetzt ein Feld in der Kamera-Instanz (unter dem Verifizierungscode) statt in der Kachel; die Variable „Bemerkung“ und EZVIZ_SetNote entfallen

**Version 1.0 (Build 19)**
- Bessere Bildqualität: Standbild lokal in voller Auflösung aus dem Hauptstream (einstellbar „Hoch“/„Schnell“), höhere JPEG-Qualität, große Bilder erst ab 1920 Pixel Breite verkleinert
- Bemerkungsfeld in jeder Kamerakachel (antippen zum Bearbeiten), gespeichert in der neuen Variable „Bemerkung“; Befehl EZVIZ_SetNote

**Version 1.0 (Build 18)**
- Keine chinesischen Fehlertexte mehr: Meldungen der EZVIZ-Cloud werden nur noch übernommen, wenn sie lesbar sind
- Fehlerhinweis in der Kachel verschwindet, sobald ein neueres Bild da ist (spätestens nach 30 Minuten)
- Kachel behält die richtige Bildart (Standbild/Alarmbild) auch bei Statusupdates

**Version 1.0 (Build 17)**
- Akku-Kameras: Schläft die Kamera, erscheint statt „Code 2009“ ein verständlicher Hinweis; die Kachel zeigt dann das letzte Alarmbild
- Fehlt das Alarmbild (z. B. nach Neustart), wird es beim nächsten Abruf automatisch nachgeladen
- Kachel zeigt immer das neuere Bild (Standbild oder Alarmbild)

**Version 1.0 (Build 16)**
- Fehler behoben: Standbild und Alarmbild lagen nur im Speicher und fehlten nach einem Neustart von Symcon (rotes Ausrufezeichen) – Bilder werden jetzt als Datei gespeichert; fehlende Bilder werden beim Übernehmen entfernt und neu angelegt

**Version 1.0 (Build 15)**
- Fehler behoben: Konto und Kamera konnten sich gegenseitig blockieren (Konto schickte Daten an die Kamera, während die Kamera beim Konto anfragte) – danach standen alle Timer beider Instanzen. Das Konto ruft die Kameras jetzt nicht mehr auf; es setzt nur die Variable „Letzter Abruf“, und die Kameras holen ihre Daten daraufhin selbst
- Neue Variable „Letzter Abruf“ im Konto

**Version 1.0 (Build 14)**
- „Timer prüfen“ zeigt zusätzlich die laufenden Skripte von Symcon (Fehlersuche bei stehenden Timern)
- FFmpeg wird bei Zeitüberschreitung sicher beendet und bleibt nicht im Hintergrund hängen

**Version 1.0 (Build 13)**
- Kachel fordert selbst alle 30 s ein neues Standbild an, solange sie angezeigt wird (unabhängig von den Symcon-Timern)
- Button „Timer prüfen“ in Konto und Kamera zeigt Intervall, letzten und nächsten Lauf der Timer (Fehlersuche)

**Version 1.0 (Build 12)**
- Konto erneuert die Sitzung jetzt bei jedem Fehler der Geräteliste (EZVIZ meldet eine abgelaufene Sitzung nicht immer gleich) – vorher konnte der automatische Abruf dauerhaft hängen bleiben
- Konto zeigt, ob der automatische Abruf läuft, und den letzten Fehler; Fehler stehen einmalig im Meldungsfenster

**Version 1.0 (Build 11)**
- Fehler behoben: Automatische Abrufe (Konto alle 60 s, Standbild alle 30 s, „Bewegung erkannt“ zurücksetzen) liefen nicht – alle Timer rufen jetzt direkt Modulfunktionen auf
- Sofortiges Standbild (nach Übernehmen, Alarm, Schwenken) hat einen eigenen Timer und stört das feste Intervall nicht mehr
- Instanz zeigt, ob das automatische Standbild läuft, wann es zuletzt lief bzw. warum es aus ist

**Version 1.0 (Build 10)**
- Fehler behoben: Das automatische Standbild lief nur einmal – der Timer-Aufruf kollidierte mit dem gleichnamigen Medienobjekt „Standbild“

**Version 1.0 (Build 9)**
- Die Zeile „Standbild: zuletzt …“ in der Instanz aktualisiert sich sofort nach jedem Bild (auch beim automatischen Abruf), ohne die Instanz neu zu öffnen

**Version 1.0 (Build 8)**
- Fehler behoben: „Call to undefined function set_time_limit()“ beim Holen des Standbilds (Funktion ist in Symcon gesperrt)
- FFmpeg-Aufruf weicht auf andere Wege aus, falls Symcon weitere PHP-Funktionen sperrt

**Version 1.0 (Build 7)**
- „Standbild jetzt holen“ zeigt sofort das Ergebnis bzw. den Grund, warum es nicht geklappt hat
- FFmpeg läuft mit eigener Zeitgrenze (15 s) und kann nicht mehr hängen bleiben
- Verständliche Fehlertexte (Verifizierungscode falsch, RTSP ausgeschaltet, Stream-Pfad falsch, Kamera nicht erreichbar)
- Befehl EZVIZ_GetSnapshotStatus

**Version 1.0 (Build 6)**
- Standbild zuverlässiger: zweiter lokaler Versuch, längere Wartezeit (20 s), bei Problemen nur 3 statt 10 Minuten Cloud
- Grund für ein fehlendes Bild steht in der Kachel und in der Instanz („Standbild: zuletzt … – letzter Versuch …“)
- Kachel kennzeichnet, wenn statt des Standbilds das Alarmbild gezeigt wird
- Akku-Kameras holen das Standbild nur noch bei Alarm und auf Knopfdruck (über die Cloud) – regelmäßig nur, wenn ausdrücklich eingeschaltet
- Große Bilder werden auf 1280 Pixel Breite verkleinert, damit die Kachel sie schneller bekommt

**Version 1.0 (Build 5)**
- Akku-Kameras: Akkustand in der Kachel (grün/gelb/rot) und Variable „Akku schwach“
- Meldung an die Visualisierung (Benachrichtigung + Push), wenn der Akku die einstellbare Grenze erreicht – einmalig, erneut erst nach dem Laden
- Button „Akku-Meldung testen“ und Befehl EZVIZ_TestBatteryNotification

**Version 1.0 (Build 4)**
- FFmpeg wird automatisch im Symcon-Ordner gefunden und bei Bedarf ausführbar gemacht – im Docker-Container genügt es, die Datei „ffmpeg“ per File Station dort abzulegen

**Version 1.0 (Build 3)**
- FFmpeg wird auf der Synology automatisch gefunden (SynoCommunity-Pakete ffmpeg7/6/5)

**Version 1.0 (Build 2)**
- Schneller Bildaufbau: Standbild wird im Hintergrund regelmäßig geholt (lokal per FFmpeg oder über die Cloud) und ist sofort sichtbar; bei Alarm und nach dem Schwenken sofort erneuert
- Stream-Auswahl mit Unterstream als Standard (lädt deutlich schneller als der Hauptstream)
- Eigene Kachel: Standbild mit Alter, Online-/Bewegungsanzeige, Schwenk-Pfeilen, Knöpfe für Bild, Bewegungserkennung und Schlafmodus
- Befehl EZVIZ_UpdateSnapshot

**Version 1.0 (Build 1)**
- Erste Version: Konto mit Zwei-Faktor-Anmeldung und Sitzungserneuerung, Konfigurator, Kamera mit Bewegungserkennung, Schaltern, Schwenken, Alarmbild (inkl. Entschlüsselung) und RTSP-Livestream
