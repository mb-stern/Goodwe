# GoodWe Inverter für IP-Symcon

Dieses Modul ermöglicht die Abfrage und Steuerung eines kompatiblen GoodWe-Wechselrichters über Modbus. Eine am Wechselrichter angeschlossene Batterie wird ebenfalls unterstützt.

Getestet wurde das Modul mit einem **GoodWe ET Plus+ 10 kW** und einer **GoodWe Lynx Home F Plus** Batterie. Andere GoodWe-Wechselrichter, insbesondere Geräte der Serien **ET, EH, BH und BT**, dürften ebenfalls kompatibel sein, sofern sie dieselben Modbus-Register unterstützen.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanz in IP-Symcon](#4-einrichten-der-instanz-in-ip-symcon)
5. [Konfiguration](#5-konfiguration)
6. [Status- und Steuervariablen](#6-status--und-steuervariablen)
7. [Zusätzliche Berechnungen](#7-zusätzliche-berechnungen)
8. [Variablenprofile](#8-variablenprofile)
9. [Visualisierung](#9-visualisierung)
10. [PHP-Befehlsreferenz](#10-php-befehlsreferenz)

## 1. Funktionsumfang

* Abfrage ausgewählter Modbus-Register des Wechselrichters.
* Registerauswahl direkt im Konfigurationsformular.
* Datenpunkte für:

  * Wechselrichter (WR)
  * Smartmeter (SM)
  * Batterie 1 (BAT)
  * Batterie 2 (BAT2)
  * PV-Strings und MPP-Tracker
  * weitere unterstützte Wechselrichterdaten
* Blockweise Abfrage der ausgewählten Register für eine möglichst effiziente Kommunikation.
* Automatisches Erstellen und Löschen der zu den ausgewählten Registern gehörenden Variablen.
* Steuerung unterstützter schreibbarer Register direkt aus IP-Symcon.
* Anzeige von BMS-Warnungen und BMS-Alarmen als lesbarer Text.
* Optionale Berechnung der aktuell maximal möglichen Lade- und Entladeleistung der Batterien.
* Manuelles Aktualisieren aller ausgewählten Daten über das Konfigurationsformular oder per PHP-Befehl.

## 2. Voraussetzungen

* IP-Symcon ab Version 8.1.
* Kompatibler GoodWe-Wechselrichter mit Modbus-Unterstützung.
* Netzwerk- oder RS485-Verbindung zum Wechselrichter.
* Für Batteriedaten eine mit dem Wechselrichter verbundene und unterstützte Batterie.

Getestete Komponenten:

* GoodWe ET Plus+ 10 kW
* GoodWe Lynx Home F Plus

Andere Geräte können ebenfalls funktionieren, sofern die verwendeten Modbus-Register übereinstimmen.

## 3. Software-Installation

Das Modul kann über den IP-Symcon Modul-Store installiert werden.

Nach der Installation steht die Instanz **GoodWe Inverter** zur Verfügung.

## 4. Einrichten der Instanz in IP-Symcon

Unter **Instanz hinzufügen** die Instanz **GoodWe Inverter** auswählen.

Die Kommunikation erfolgt über ein **Modbus-Gateway**.

Die Geräte-ID des GoodWe-Wechselrichters ist üblicherweise:

**247**

Bei direkter Modbus-TCP-Verbindung über das LAN-Modul des Wechselrichters wird normalerweise Port:

**502**

verwendet.

Bei Verwendung eines separaten Modbus-/RS485-Adapters muss dessen entsprechende IP-Adresse bzw. dessen Port verwendet werden.

## 5. Konfiguration

### Register auswählen

Im Konfigurationsformular können die gewünschten Register über Checkboxen ausgewählt werden.

Zu jedem Register werden die Modbus-Adresse und die Bezeichnung angezeigt.

Nur ausgewählte Register werden regelmäßig abgefragt und als Variablen angelegt.

Wird ein Register wieder abgewählt, entfernt das Modul die dazugehörige Variable automatisch.

### Abfrageintervall

Das Abfrageintervall bestimmt, wie häufig die ausgewählten Register gelesen werden.

Standard:

**10 Sekunden**

Die Register werden soweit möglich blockweise zusammengefasst und gemeinsam abgefragt.

### Zusätzliche Werte berechnen

Optional können folgende Werte berechnet werden:

* Maximal mögliche Ladeleistung Batterie 1
* Maximal mögliche Entladeleistung Batterie 1
* Maximal mögliche Ladeleistung Batterie 2
* Maximal mögliche Entladeleistung Batterie 2

Die hierfür benötigten BMS-Daten werden vom Modul automatisch berücksichtigt.

### Werte lesen

Mit der Schaltfläche **Werte lesen** können alle aktuell ausgewählten Datenpunkte sofort aktualisiert werden.

## 6. Status- und Steuervariablen

Die benötigten Variablen werden abhängig von der Registerauswahl automatisch erstellt bzw. entfernt.

Unter anderem können – abhängig vom Wechselrichter und der gewählten Registerauswahl – Daten aus folgenden Bereichen zur Verfügung stehen:

* Wechselrichter
* Netz
* Smartmeter
* PV-Strings
* MPP-Tracker
* Batterie 1
* Batterie 2
* Temperaturen
* Leistungen
* Spannungen und Ströme
* Energiezähler
* Betriebszustände
* BMS-Warnungen und Alarme

### Schreibbare Register

Einige Register können direkt aus IP-Symcon verändert werden.

Dazu gehören unter anderem:

#### Batterie 1

* **BAT - Min SOC online**
  Minimaler Ladezustand der Batterie bei vorhandener Netzverbindung.

* **BAT - Min SOC offline**
  Minimaler Ladezustand der Batterie bei fehlender Netzverbindung.

* **BAT - EMSPowerMode**
  Betriebsmodus des Energiemanagementsystems.

* **BAT - EMSPowerSet**
  Leistungsvorgabe für entsprechende EMS-Betriebsarten.

#### Batterie 2

Für eine zweite Batterie stehen – sofern vom System unterstützt – ebenfalls entsprechende SOC-Einstellungen zur Verfügung.

#### Wechselrichter

Je nach unterstütztem Register können zusätzlich Einstellungen des Wechselrichters verändert werden, beispielsweise:

* Betriebsmodus
* Einspeisung
* Einspeisegrenze
* Backup-Funktion
* Modbus-TCP-Verhalten
* Neustart des Wechselrichters

**Achtung:** Schreibbare Register sollten nur verändert werden, wenn deren Funktion und Auswirkungen bekannt sind. Falsche Einstellungen können den Betrieb der Anlage beeinflussen.

Für den normalen Betrieb des EMS sollte der passende automatische Betriebsmodus bevorzugt werden.

## 7. Zusätzliche Berechnungen

GoodWe stellt nicht für alle gewünschten Werte einen direkt nutzbaren Datenpunkt bereit.

Das Modul kann deshalb aus den vom BMS gemeldeten maximalen Spannungs- und Stromwerten die momentan maximal mögliche Lade- bzw. Entladeleistung berechnen.

Die Berechnung erfolgt grundsätzlich aus:

**Leistung = Spannung × Strom**

Diese Funktion kann für Batterie 1 und Batterie 2 getrennt aktiviert werden.

## 8. Variablenprofile

Das Modul legt die benötigten Variablenprofile automatisch an.

Dazu gehören unter anderem:

| Profil                | Verwendung                        |
| --------------------- | --------------------------------- |
| `Goodwe.EMSPowerMode` | EMS-Betriebsmodus                 |
| `Goodwe.Watt`         | Leistung in Watt                  |
| `Goodwe.Percent`      | Prozentwerte                      |
| `Goodwe.WattEMS`      | EMS-Leistungsvorgabe              |
| `Goodwe.kOhm`         | Isolationswiderstand              |
| `Goodwe.Mode`         | Batteriebetriebszustand           |
| `Goodwe.WorkMode`     | Betriebsmodus des Wechselrichters |
| `Goodwe.GridMode`     | Netzzustand/Betriebszustand       |

Weitere Standardprofile von IP-Symcon werden abhängig von den jeweiligen Einheiten verwendet.

## 9. Visualisierung

Alle Variablen können wie gewohnt in der IP-Symcon Visualisierung verwendet werden.

Bei schreibbaren Registern aktiviert das Modul automatisch eine Aktion. Diese Werte können dadurch direkt aus der Visualisierung verändert werden.

## 10. PHP-Befehlsreferenz

### Wechselrichterdaten aktualisieren

```php
GoodWeInverter_FetchInverterData(12345);
```

`12345` ist durch die Instanz-ID der GoodWe-Inverter-Instanz zu ersetzen.

Der Befehl liest die aktuell ausgewählten Register und aktualisiert die zugehörigen Variablen.

### Maximale Batterieleistung neu berechnen

```php
GoodWeInverter_CalculateMaxPower(12345);
```

Berechnet die aktivierten maximalen Lade- und Entladeleistungen erneut anhand der zuletzt eingelesenen BMS-Daten.
