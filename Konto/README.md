# EZVIZ Konto

Meldet sich am EZVIZ-Konto an, hält die Sitzung und fragt regelmäßig alle Geräte und die neuesten Alarme ab.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis | Muss bestätigt werden, sonst bleibt die Instanz inaktiv |
| E-Mail / Benutzername, Passwort | Zugangsdaten der EZVIZ-App |
| Bestätigungscode | Nur nötig, wenn EZVIZ die Zwei-Faktor-Anmeldung verlangt (Status „Bestätigungscode wurde angefordert“) |
| Geräte und Alarme abfragen alle | Abfrageintervall in Sekunden (Standard 60, 0 = aus, mindestens 15) |
| Alarme sofort per Push empfangen | Legt die Instanz „EZVIZ Push“ an; Alarme kommen dann in Sekunden statt beim nächsten Abruf (siehe [EZVIZ Push](../Push/README.md)) |
| Server | Standard `apiieu.ezvizlife.com`. Bei einer anderen Region stellt das Modul automatisch um |

## Anmeldung

- Nach erfolgreicher Anmeldung wird die Sitzung bei Ablauf automatisch erneuert – ein neuer Bestätigungscode ist dann nicht nötig.
- Nach falschen Zugangsdaten oder fehlendem Code versucht das Modul **nicht** selbstständig weiter, damit EZVIZ das Konto nicht sperrt. Nach der Korrektur „Übernehmen“ oder „Verbindung testen“.
- Lehnt EZVIZ die Anmeldung nur vorübergehend ab (zu viele Anfragen, Konto kurz gesperrt, unbekannter Fehler), versucht das Modul es nach einer Wartezeit erneut: 5 Minuten, dann 10, 20 … höchstens 6 Stunden. „Übernehmen“ oder „Verbindung testen“ versuchen es sofort.
- Netz- oder Serverstörungen gelten nicht als Anmeldefehler: Die Sitzung bleibt erhalten, der nächste Abruf versucht es erneut.
- In der EZVIZ-App erscheint das Modul als angemeldetes Gerät „Symcon“.

## PHP-Befehle

```php
bool  EZVIZ_Login(int $InstanzID);       // Neu anmelden und Geräte abfragen
bool  EZVIZ_RefreshAll(int $InstanzID);  // Alle Geräte und Alarme sofort abfragen
void  EZVIZ_RefreshSoon(int $InstanzID); // Abruf in Kürze anstoßen (kehrt sofort zurück)
array EZVIZ_GetDevices(int $InstanzID);  // [Seriennummer => [name, model, category, online]]
```

Nur intern: `EZVIZ_GetPushInfo` (von der Push-Instanz aufgerufen, liefert die Push-Zugangsdaten samt Sitzung – nicht in eigenen Skripten verwenden oder ausgeben) und `EZVIZ_GetTimerInfo` (Button „Timer prüfen“). `EZVIZ_RefreshAll` ist zugleich das Ziel des Abruf-Timers.
