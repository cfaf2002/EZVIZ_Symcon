# EZVIZ Push (Sofort-Alarme)

Empfängt Alarme sofort über den Push-Kanal, den auch die EZVIZ-App nutzt. Bei jedem Alarm fragt das Konto sofort ab – „Bewegung erkannt“, Alarmbild und Bewegungsmeldung kommen damit in wenigen Sekunden statt erst beim nächsten Abruf.

## Einrichtung

Nichts zu tun: Im **EZVIZ Konto** unter „Abfrage“ den Schalter **„Alarme sofort per Push empfangen“** einschalten und übernehmen. Das Konto legt diese Instanz (unter dem Konto) samt der Verbindung „EZVIZ Push Verbindung“ (Client Socket) automatisch an – und entfernt beides wieder, wenn der Schalter ausgeschaltet wird.

Beim Einschalten meldet sich das Konto einmal neu an (wie die Android-App). Fordert EZVIZ dabei einen Bestätigungscode an, diesen im Konto eintragen.

## Variablen

| Variable | Beschreibung |
| :------- | :----------- |
| Push verbunden | Verbindung zum Push-Server steht |
| Letzter Push-Alarm | Zeitpunkt des letzten Alarms über Push |
| Letzte Push-Kamera | Uhrzeit und Name der Kamera, die den letzten Push-Alarm ausgelöst hat (mit Meldungstext, falls vorhanden) |
| Push-Status | Aktueller Zustand bzw. letzter Fehler |

Bricht die Verbindung ab, baut die Instanz sie automatisch neu auf (Wartezeit steigend von 30 Sekunden bis 15 Minuten). Der normale Abruf des Kontos läuft unabhängig davon weiter.

## Fehlersuche

Button „Push-Verbindung neu aufbauen“. Details im Debug der Instanz.
