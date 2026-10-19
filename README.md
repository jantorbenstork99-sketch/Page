# ECHO

Ein Vue-3-Frontend mit einem PHP-/SQLite-Backend für die Anmeldung und den internen Arbeitsbereich der Gruppierung ECHO.

## Voraussetzungen

- Node.js 20.19+ oder 22.12+
- PHP 8.1+ mit `pdo_sqlite` und `session`

## Lokal starten

1. Installiere die JavaScript-Abhängigkeiten mit `npm install`.
2. Lege bei Bedarf das erste Administratorkonto über `php backend/create-user.php` an. Vergib einen eindeutigen Benutzernamen aus 3–32 Buchstaben, Ziffern oder den Zeichen `.`, `_`, `-`; wähle als Rollen-ID `admin`. Die Passworteingabe wird im Terminal verborgen und akzeptiert beliebige Zeichen von 1 bis 1024 Bytes.
3. Starte das PHP-Backend in einem Terminal: `npm run dev:backend`.
4. Starte das Vue-Frontend in einem zweiten Terminal: `npm run dev`.
5. Öffne die von Vite angezeigte lokale Adresse und melde dich mit dem angelegten Konto an.

Die SQLite-Datei wird bei der ersten Backend-Anfrage unter `backend/data/app.sqlite` angelegt. Ein abweichender Datenbankpfad lässt sich mit `APP_DB_PATH` konfigurieren.

## Aufbau

- `page.vue` enthält Login, Übersicht und die linke Navigation.
- `backend/public/index.php` stellt die sitzungsgebundenen JSON-Endpunkte bereit.
- `backend/create-user.php` legt Konten mit einem Benutzernamen und einer vorhandenen Rollen-ID an; Passwörter werden gehasht, und es gibt keine öffentliche Registrierung. Berechtigte Personen können im internen Bereich Mitglieder hinzufügen, bearbeiten und deaktivieren sowie eigene Rollen anlegen und deren Rechte einzeln festlegen. Rechte zum Ansehen und Verwalten von Mitgliedern, Rollen, Lager und Bestellungen werden serverseitig geprüft. Die Systemrolle „Administrator“ besitzt alle Rechte und ist geschützt; „Mitglied“ ist eine eingeschränkte Ausgangsrolle. Vorhandene Konten behalten ihren Benutzernamen; ältere Konten erhalten bei der Migration einen aus ihrem bisherigen Login abgeleiteten Benutzernamen mit ID-Suffix.
- Im Lagerbereich können Personen mit der Berechtigung `warehouses_manage` Lager-Spalten hinzufügen, umbenennen und löschen. Mit `inventory_manage` lassen sich je Mitglied und Lager Schließfachnummer, PIN und Inhalt speichern. PINs werden nur Personen mit `inventory_manage` über die API angezeigt; für andere Lager-Leseberechtigte bleiben sie verborgen. Vorhandene Freitext-Einträge bleiben bei der Migration als Inhalt erhalten. Die Mitglieder werden nach der unter „Rollen & Rechte“ einstellbaren Rollenrangfolge und anschließend alphabetisch sortiert; die Rangfolge kann mit `members_order` geändert werden.
- Im Reiter „Produkte“ können Rollen mit `products_view` die Produktliste und berechneten VK-Preise ansehen. `products_manage` erlaubt Produkte anzulegen, zu bearbeiten und zu löschen; `product_markup_manage` steuert separat den Standard-Aufschlag für neue Produkte sowie den individuellen Aufschlag jedes Produkts. Der VK wird je Produkt als EK × (1 + Aufschlag / 100) mit kaufmännischer Rundung auf Cent berechnet. EK und Aufschläge werden mit zwei Nachkommastellen gespeichert. Bestehende Produkte übernehmen bei der Datenbankmigration den bisher eingestellten Standard-Aufschlag.
- Im Reiter „Bestellungen“ können Personen mit `orders_manage` Produkte und Mengen auswählen, Name, Telefonnummer und Gruppierung eintragen und die Bestellung speichern. Die Gesamtsumme wird serverseitig mit den aktuellen Produkt-VK-Preisen berechnet. Bestellungen speichern Positions-, Mengen- und Preis-Snapshots; die letzten 50 Bestellungen erscheinen in der Bestellhistorie.
- Die Kennzahlen und Artikel der Übersicht sind Beispieldaten und werden nicht gespeichert.

Für den Produktivbetrieb müssen Frontend und `/api` unter derselben HTTPS-Origin erreichbar sein. Verwende einen PHP-fähigen Webserver mit `backend/public` als Dokumentenstamm, sichere die SQLite-Datei und Backups vor Zugriff aus dem Web und richte zusätzlich passende Sicherungen und Überwachung ein. Der Vite-Entwicklungsserver ist nicht für den Produktivbetrieb gedacht.
