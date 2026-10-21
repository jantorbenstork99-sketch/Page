# NACHTHAFEN

NACHTHAFEN ist ein geschlossener, fiktiver Auktionsmarktplatz für ein Roleplay-Projekt. Alle Angebote und Gebote sind Rollenspiel-Inhalte; die Anwendung wickelt keine realen Waren oder Zahlungen ab.

## Voraussetzungen

- Node.js 20.19+ oder 22.12+
- PHP 8.1+ mit `pdo_sqlite` und `session`

## Lokal starten

1. Installiere die JavaScript-Abhängigkeiten: `npm install`.
2. Lege das erste Administratorkonto an: `php backend/create-user.php`. Wähle als Rollen-ID `admin`; das Passwort wird nur für den Admin-Zugang verwendet.
3. Starte das PHP-Backend in einem Terminal: `npm run dev:backend`.
4. Starte das Frontend in einem zweiten Terminal: `npm run dev`.
5. Melde dich in der Admin-Ansicht mit dem Administratorkonto an und erstelle Zugangscodes.

Die SQLite-Datei wird unter `backend/data/app.sqlite` angelegt. Ein alternativer Speicherort lässt sich mit `APP_DB_PATH` konfigurieren.

## Zugänge und Auktionen

- Mitglieder melden sich mit einem vom Admin ausgestellten `MIT-`-Code und Decknamen an. Verkäufer erhalten einen eigenen `VER-`-Code, mit dem sie sich direkt anmelden und Angebote einstellen können. Beide Code-Typen werden beim ersten Einlösen an ein Konto gebunden und sind danach für weitere Anmeldungen wiederverwendbar.
- Jeder Code wird beim Erstellen einer Roleplay-Gruppe zugeordnet. Der Admin kann vorhandene Gruppennamen wiederverwenden oder eine neue Gruppe eintragen; die Codeübersicht ist nach Gruppen sortiert.
- Der Admin kann Mitglieder- und Verkäufer-Codes erstellen und sperren. Codes werden nur gehasht gespeichert; der Klartext eines neuen Codes wird beim Erstellen einmalig angezeigt. Das Sperren eines Mitglieds-Codes deaktiviert auch das zugehörige Konto. Das Sperren eines Verkäufer-Codes entzieht die Veröffentlichungsberechtigung.
- Verkäufer reichen Angebote zunächst zur Prüfung ein. Sie erscheinen erst nach dem Start durch den Admin im Marktplatz; der Admin kann laufende Auktionen auch beenden. Nur Admins dürfen den Auktionsstatus ändern. Bieter müssen ein höheres Gebot als das aktuelle abgeben, und auf eigene Angebote kann nicht geboten werden. Gebote und Statusänderungen werden serverseitig geprüft.
- Freigaben haben einen eigenen Admin-Menüpunkt mit der Anzahl wartender Angebote und einer Sammelfreigabe. In der Auktionssteuerung kann der Admin für eine einzelne Auktion oder alle freigegebenen laufenden Auktionen gemeinsam eine letzte Gebotsphase von 10 Sekunden bis 24 Stunden starten; alle bisherigen Bieter werden benachrichtigt. Beim Sammelstart wird ein bereits laufender Timer auf die gewählte Dauer zurückgesetzt. Der Countdown wird serverseitig gespeichert, Gebote bleiben bis zum Ablauf möglich und die Auktion wird beim nächsten API-Aufruf nach Fristablauf automatisch geschlossen.
- In der Admin-Auktionssteuerung ist der Gebotsverlauf jeder laufenden Auktion mit Bieternamen, Gebotshöhe und Zeitpunkt sichtbar. Die Gebotsdetails werden nur für Admin-Konten über die API ausgegeben.
- Wird ein Höchstgebot überboten, erhält die zuvor höchstbietende Person eine persönliche Nachricht im Posteingang; ungelesene Nachrichten werden alle 15 Sekunden aktualisiert. Nach Auktionsende sieht der Verkäufer im Archiv den Gewinnernamen und den Endpreis. Diese Käuferdetails werden über die API ausschließlich an den Verkäufer und Admin ausgegeben.
- Im Admin-Menü zeigt das Aktivitätsprotokoll die gespeicherten Anmeldungen, Abmeldungen, Codeverwaltung, Angebote, Gebote sowie weitere protokollierte Systemänderungen. Die Ansicht ist nur für Admins verfügbar, lädt ältere Einträge bei Bedarf nach und speichert keine Klartext-Codes oder Passwörter.
- Der Administrationszugang ist auf Konten mit der Systemrolle `admin` beschränkt. Neue Admin-Konten lassen sich über `php backend/create-user.php` anlegen.

## Aufbau

- `page.vue` enthält die Anmeldung, den Marktplatz, Verkäuferbereich und die Admin-Oberfläche.
- `backend/public/index.php` stellt die sitzungsgebundenen JSON-Endpunkte bereit und prüft Codes, Rollen, Gebote sowie CSRF-Tokens serverseitig.
- `backend/src/database.php` erstellt und migriert die SQLite-Tabellen.

Für den Produktivbetrieb müssen Frontend und `/api` unter derselben HTTPS-Origin erreichbar sein. Verwende einen PHP-fähigen Webserver mit `backend/public` als Dokumentenstamm, halte die SQLite-Datei und Sicherungskopien außerhalb des Webzugriffs und sichere Sitzungs-/Datenbankzugriffe angemessen. Der Vite-Entwicklungsserver ist nicht für den Produktivbetrieb gedacht.
