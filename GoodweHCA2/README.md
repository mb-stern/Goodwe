# GoodWe HCA2 Wallbox für IP-Symcon

Dieses Modul ermöglicht die direkte Abfrage und Steuerung einer kompatiblen **GoodWe HCA G2 Wallbox** über Modbus.

Im Gegensatz zu früheren Versionen der GoodWe-Wallbox-Anbindung ist **keine SEMS-API erforderlich**. Statuswerte und Steuerbefehle werden direkt über die Modbus-Verbindung der Wallbox verarbeitet.

Unterstützt werden aktuell folgende Modelle:

* **GW7K-HCA-20**
* **GW11K-HCA-20**
* **GW22K-HCA-20**

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanz in IP-Symcon](#4-einrichten-der-instanz-in-ip-symcon)
5. [Konfiguration](#5-konfiguration)
6. [Statusvariablen](#6-statusvariablen)
7. [Steuerung der Wallbox](#7-steuerung-der-wallbox)
8. [Korrektur der Sollleistung](#8-korrektur-der-sollleistung)
9. [Variablenprofile](#9-variablenprofile)
10. [Visualisierung](#10-visualisierung)
11. [PHP-Befehlsreferenz](#11-php-befehlsreferenz)

## 1. Funktionsumfang

* Direkte Kommunikation mit der GoodWe HCA G2 Wallbox über Modbus.
* Keine SEMS-API und keine GoodWe-Cloud-Zugangsdaten erforderlich.
* Auswahl der gewünschten Wallbox-Register im Konfigurationsformular.
* Automatisches Erstellen und Löschen der zugehörigen IP-Symcon-Variablen.
* Blockweise Abfrage der ausgewählten Modbus-Register.
* Einstellbares Abfrageintervall.
* Auswahl des verwendeten Wallbox-Modells.
* Steuerung unterstützter Wallbox-Funktionen direkt aus IP-Symcon.
* Modellabhängige Begrenzung der einstellbaren Ladeleistung.
* Einstellbare Korrektur der Sollleistung.
* Rücklesen geschriebener Werte zur Kontrolle der erfolgreichen Übernahme.
* Manuelle Aktualisierung aller ausgewählten Werte über das Konfigurationsformular oder per PHP-Befehl.

## 2. Voraussetzungen

* IP-Symcon ab Version 8.1.
* Unterstützte GoodWe HCA G2 Wallbox.
* Erreichbare Modbus-Verbindung zur Wallbox.

Aktuell sind folgende Wallbox-Modelle im Modul vorgesehen:

| Modell       | Maximale Ladeleistung |
| ------------ | --------------------: |
| GW7K-HCA-20  |                  7 kW |
| GW11K-HCA-20 |                 11 kW |
| GW22K-HCA-20 |                 22 kW |

## 3. Software-Installation

Das Modul kann über den IP-Symcon Modul-Store installiert werden.

Nach der Installation steht die Instanz **GoodWe HCA2** zur Verfügung.

## 4. Einrichten der Instanz in IP-Symcon

Unter **Instanz hinzufügen** die GoodWe-HCA2-Wallbox-Instanz auswählen.

Die Kommunikation erfolgt über ein **Modbus-Gateway**.

Anschließend muss die Modbus-Verbindung zur Wallbox entsprechend der vorhandenen Installation eingerichtet werden.

Die Wallbox wird danach vollständig lokal über Modbus abgefragt und gesteuert. Eine Verbindung zur SEMS-API ist für diese Instanz nicht erforderlich.

## 5. Konfiguration

### Wallbox-Register auswählen

Im Konfigurationsformular werden die verfügbaren Wallbox-Register angezeigt.

Über die Checkbox **Aktiv** kann für jeden Datenpunkt festgelegt werden, ob er von IP-Symcon verwendet werden soll.

Zu jedem Datenpunkt werden angezeigt:

* Modbus-Adresse
* Bezeichnung
* Aktivierungsstatus

Die entsprechenden Variablen werden automatisch erstellt.

Wird ein Register deaktiviert, wird die dazugehörige Variable wieder entfernt.

### Wallbox-Modell

Das verwendete Wallbox-Modell muss ausgewählt werden.

Zur Verfügung stehen:

* GW7K-HCA-20
* GW11K-HCA-20
* GW22K-HCA-20

Die Auswahl ist insbesondere für die Begrenzung der maximal einstellbaren Sollleistung relevant.

### Abfrageintervall

Das Abfrageintervall bestimmt, wie häufig die ausgewählten Modbus-Register gelesen werden.

Standard:

**5 Sekunden**

### Werte lesen

Über die Schaltfläche **Werte lesen** können die ausgewählten Wallbox-Daten jederzeit manuell aktualisiert werden.

## 6. Statusvariablen

Aktuell stehen unter anderem folgende Datenpunkte zur Verfügung:

| Variable                     | Beschreibung                                |
| ---------------------------- | ------------------------------------------- |
| WB - Spannung L1             | Spannung Phase L1                           |
| WB - Spannung L2             | Spannung Phase L2                           |
| WB - Spannung L3             | Spannung Phase L3                           |
| WB - Strom L1                | Strom Phase L1                              |
| WB - Strom L2                | Strom Phase L2                              |
| WB - Strom L3                | Strom Phase L3                              |
| WB - Ladeleistung            | Aktuelle Ladeleistung                       |
| WB - Energie aktuelle Ladung | Geladene Energie des aktuellen Ladevorgangs |
| WB - Status                  | Betriebs-/Ladestatus der Wallbox            |
| WB - Leistungsklasse         | Leistungsklasse der Wallbox                 |
| WB - Ausführung              | Ausführung/Typ der Wallbox                  |
| WB - Energie gesamt          | Gesamte geladene Energie                    |
| WB - Fahrzeugverbindung      | Zustand der Verbindung zum Fahrzeug         |

Zusätzlich werden die schreibbaren Einstellungen ebenfalls als Variablen angelegt.

## 7. Steuerung der Wallbox

Folgende Funktionen können aktuell direkt über IP-Symcon gesteuert werden:

### Automatische Phasenumschaltung

**WB - Automatische Phasenumschaltung**

Aktiviert bzw. deaktiviert die automatische Phasenumschaltung der Wallbox.

### Sollleistung

**WB - Sollleistung**

Legt die gewünschte Ladeleistung fest.

Der zulässige Maximalwert wird automatisch an das in der Konfiguration ausgewählte Wallbox-Modell angepasst.

### Batterie-Entladegrenze

**WB - Batterie Entladegrenze**

Legt die Batterie-Entladegrenze in Prozent fest.

### Lademodus

**WB - Lademodus**

Unterstützte Modi:

* Sofortladen
* PV-Überschussladen
* PV + Batterie

### Laden Ein/Aus

**WB - Laden Ein/Aus**

Ermöglicht das Starten bzw. Stoppen des Ladevorgangs über die Wallbox.

### Sicherheit beim Schreiben

Beim Ändern eines Wallbox-Wertes wird der neue Wert zunächst per Modbus geschrieben.

Anschließend liest das Modul das betreffende Register erneut aus. Erst wenn der geschriebene Wert durch das Rücklesen bestätigt werden konnte, wird die Änderung als erfolgreich übernommen.

Dadurch wird vermieden, dass ein lediglich abgesendeter, aber von der Wallbox nicht übernommener Wert in IP-Symcon als erfolgreich angezeigt wird.

## 8. Korrektur der Sollleistung

Für die Sollleistung kann in der Konfiguration eine Korrektur zwischen:

**-10 % und +10 %**

eingestellt werden.

Standard:

**0 %**

Dabei gilt:

* **0 %** = keine Korrektur
* **negativer Wert** = geringere Leistung an die Wallbox übertragen
* **positiver Wert** = höhere Leistung an die Wallbox übertragen

Die Korrektur wird beim Schreiben auf die Wallbox angewendet.

Beim anschließenden Einlesen berücksichtigt das Modul die Korrektur invers, sodass in IP-Symcon weiterhin der gewünschte Sollwert dargestellt wird.

Beispiel:

Wird in IP-Symcon eine Sollleistung vorgegeben und eine Korrektur benötigt, kann die tatsächlich an die Wallbox übertragene Vorgabe angepasst werden, ohne dass dadurch die gewünschte Sollwertdarstellung in IP-Symcon verändert wird.

Die modellabhängige maximale Ladeleistung wird weiterhin berücksichtigt.

## 9. Variablenprofile

Das Modul legt die benötigten Variablenprofile automatisch an.

Dazu gehören unter anderem:

| Profil                   | Verwendung                     |
| ------------------------ | ------------------------------ |
| `GoodWeHCA2.Watt`        | Leistung                       |
| `GoodWeHCA2.Percent`     | Prozentwerte                   |
| `GoodWeHCA2.Status`      | Wallbox-Status                 |
| `GoodWeHCA2.PhaseSwitch` | Automatische Phasenumschaltung |
| `GoodWeHCA2.ChargeMode`  | Lademodus                      |
| `GoodWeHCA2.OnOff`       | Laden Ein/Aus                  |
| `GoodWeHCA2.Connection`  | Fahrzeugverbindung             |
| `GoodWeHCA2.PowerSpec`   | Leistungsklasse                |
| `GoodWeHCA2.Type`        | Wallbox-Ausführung             |

Für die Sollleistung wird zusätzlich ein instanzbezogenes Profil verwendet. Dessen Maximalwert wird automatisch an das ausgewählte Wallbox-Modell angepasst.

## 10. Visualisierung

Alle angelegten Statusvariablen können in der IP-Symcon Visualisierung verwendet werden.

Bei schreibbaren Wallbox-Registern aktiviert das Modul automatisch eine Aktion. Dadurch können beispielsweise:

* Ladeleistung
* Lademodus
* Phasenumschaltung
* Batterie-Entladegrenze
* Laden Ein/Aus

direkt aus der Visualisierung gesteuert werden.

## 11. PHP-Befehlsreferenz

### Wallbox-Daten aktualisieren

```php
GoodWeHCA2_FetchWallboxData(12345);
```

`12345` ist durch die Instanz-ID der GoodWe-HCA2-Instanz zu ersetzen.

Der Befehl liest alle aktuell ausgewählten Wallbox-Register und aktualisiert die entsprechenden IP-Symcon-Variablen.
