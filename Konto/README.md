# EZVIZ Konto

Meldet sich am EZVIZ-Konto an, hält die Sitzung und fragt regelmäßig alle Geräte und die neuesten Alarme ab.

## Einstellungen

| Einstellung | Beschreibung |
| :---------- | :----------- |
| Nutzungshinweis | Muss bestätigt werden, sonst bleibt die Instanz inaktiv |
| E-Mail / Benutzername, Passwort | Zugangsdaten der EZVIZ-App |
| Bestätigungscode | Nur nötig, wenn EZVIZ die Zwei-Faktor-Anmeldung verlangt (Status „Bestätigungscode wurde angefordert“) |
| Geräte und Alarme abfragen alle | Abfrageintervall in Sekunden (Standard 60, 0 = aus, mindestens 15) |
| Server | Standard `apiieu.ezvizlife.com`. Bei einer anderen Region stellt das Modul automatisch um |

## Anmeldung

- Nach erfolgreicher Anmeldung wird die Sitzung bei Ablauf automatisch erneuert – ein neuer Bestätigungscode ist dann nicht nötig.
- Nach falschen Zugangsdaten oder fehlendem Code versucht das Modul **nicht** selbstständig weiter, damit EZVIZ das Konto nicht sperrt. Nach der Korrektur „Übernehmen“ oder „Verbindung testen“.
- In der EZVIZ-App erscheint das Modul als angemeldetes Gerät „Symcon“.

## PHP-Befehle

```php
bool  EZVIZ_Login(int $InstanzID);       // Neu anmelden und Geräte abfragen
bool  EZVIZ_RefreshAll(int $InstanzID);  // Alle Geräte und Alarme sofort abfragen
array EZVIZ_GetDevices(int $InstanzID);  // [Seriennummer => [name, model, category, online]]
```
