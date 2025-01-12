# Modul für Goodwe für IP-Symcon
Dieses Modul ermöglicht, Daten von einem Goodwe Wechselricher und/oder einer Goodwe Wallbox abzufragen. 
Unterstützt sind Wechselrichter der Serie ET, EH, BH, BT. Andere Goodwe-Wechselrichter können möglicherweise funktionieren.
Ebenfalls kann die Goodwe Wallbox GW11K-HCA. Andere Goodwe-Wallboxen können möglicherweise ebenfalls funktionieren.


### Wichtig zu wissen zur Konfiguration von Goodwe
Die Verbindung mit dem Goode Wechselrichterder ET-, EH-, BH-, oder BT-Serie  wird über Modbus hergestellt. Die Register können nach Wunsch aus einer Liste via Konfigurationsformular ausgeählt werden. Es sind nicht alle möglichen Register in der Auswahl vorhanden. Aktuell können noch keine Ansteuerungen über Modbus gemacht werden.

Die Verbindung mit der Goodwe Wallbox GW11K-HCA wird über die SEMS-API hergestellt. Dazu werden die Zugangsdaten des SEMS-Portal und die Seriennummer der Goodwe Wallbox benötigt. Diese kann in der SEMS-APP in der Wallboxsteuerung nachgesehen werden.

Wärend der Installation des Moduls wird automatisch ein Modbus-Gateway erstellt, sofern noch keines vorhanden ist. Geräte-ID des Wechselrichters ist 247.
Danach kann die IP-Adresse des Wechselrichters in den Client Socket eingetragen werden. Der Port ist standardmässig 502, sofern der Wechselrichter über das LAN-Modul verfügt. Ansonsten den Port des Modbus-Adapters verwenden, welcher dann über RS485 mit dem Wechselrichter kommuniziert.


### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Versionen](#8-versionen)

### 1. Funktionsumfang

* Abfrage der ausgewählten Register des Wechselrichters, gruppiert nach Smartmeter (SM), Batterie (BAT)  und Wechselrichter (WR)
* Abfrage und Steuerung der Wallbox-Daten. Die Wahl der Ladeleistung (für Schnellladung), des Modus (Schnell, PV-Priorität oder PV&Batterie) ist möglich.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.0
- Goodwe Wechselrichter der ET-, EH-, BH-, oder BT-Serie und/oder eine Goodwe Wallbox GW11K-HCA

### 3. Software-Installation

* Über den Module Store kann das Modul noch nicht installiert werden da noch beta. Es muss im Store nach dem genauen Modulnamen gesucht werden.

### 4. Einrichten der Instanzen in IP-Symcon

- Unter 'Instanz hinzufügen' kann das 'Smartcar'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Selected Registers         |  Hier können die Register für die Modbus-Abfrage ausgewählt werden. Diese sind nach WR (Wechselrichter), BAT (Batterie) und SM (Smartmeter) gruppiert. Die Varieblen werden automatisch erstellt oder gelöscht.
Intervall                  |  Standard ist 5 sek. Intervall für die Abfrage der Modbus-Register
SEMS-API-Konfiguration     |  Die Konfiguration ist nur bei vorhandener Goodwe-Wallbox erforderlich, da sich diese nicht über Modbus abfragen lässt. Der Timer ist hier Standardmässig auf 30 sec eingestellt. Die Wallbox Variablen (WB) werden automatisch nach der Eingabe der Zugangsdaten erstellt bzw. gelöscht. Vorsicht, nicht zu häufig abfragen, sonst blockiert die API.
Werte lesen                |  Hiermit können alle aktvierten Datenpunkte abgefragt werden

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Es werden Variablen/Typen je nach Wahl der Scopes erstellt. Es können pro Scope mehrere Variablen erstellt werden. Beim Deaktivieren des jeweiligen Scope werden die Variablen wieder gelöscht.

#### Profile

Name   | Typ
------ | ------- 
Goodwe.EMSPowerMode     |  Integer 
Goodwe.WB_State         |  Integer  
Goodwe.WB_Mode          |  Integer 
Goodwe.WB_Power         |  Float   
Goodwe.Mode             |  Integer
Goodwe.WB_Workstate     |  Integer
Goodwe.Watt             |  Integer

### 6. WebFront

Die Variablen zur Steuerung der Fahrzeugfunktion können aus der Visualisierung heraus gesteuert werden.

### 7. PHP-Befehlsreferenz

Hier findest du die Info, wie geziehlt (zb über einen Ablaufplan) nur bestimmte Endpunkte (Scopes) abgefragt werden, um API-Calls zu sparen. 
Ein Scenario wäre, dass der SOC nur bei aktiviertem Ladevorgang alle 15min über einen Ablaufplan aktualisiert wird.
Beachte, dass nur im Konfigurationsformuler (Berechtigungen) freigegebene Scopes abgefragt werden können. Falls über einen Ablaufplan mehere Scopes nacheinander abgerufen werden ist ein Abstand von ca 2 Minuten empfehlensert, da Smartcar bei häufigerer Abfragefrequnz diese blockiert.

Befehl   | Beschreibung
------ | -------
Goodwe_FetchAll(12345);         |   Alle Datenpunkte aktualisieren
Goodwe_FetchWallboxData(12345); |   Datenpunkte der Wallbox aktualisieren
Goodwe_RequestRead(12345);      |   Datenpunkte des Wechselrichters akualisieren

### 8. Versionen

Version 1.0 (12.01.2025)
- Initiale Version