<?php
class Goodwe extends IPSModule
{
    public function __construct($InstanceID) {

        //Never delete this line!
        parent::__construct($InstanceID);

        }
    public function Create() {
		
        //Never delete this line!
        parent::Create();
        
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
        
        $this->RegisterPropertyInteger("Poller", 0);
        $this->RegisterPropertyInteger("Phase", 1);
        
        $this->RegisterTimer("Poller", 0, "Goodwe_RequestRead(\$_IPS['TARGET']);");

    }

    public function ApplyChanges() 
    {
        
        parent::ApplyChanges();
        
        // Variablen für jedes Register erstellen
        foreach ($this->Registers() as $register) {
            // Prüfen, ob die Variable schreibgeschützt ist
            $profile = $this->GetVariableProfile($register['unit'], $register['scale']);
            $this->RegisterVariableFloat(
                $register['name'],                           // Ident
                $register['name'] . " (" . $register['unit'] . ")", // Name
                $profile,                                   // Variablenprofil
                0                                           // Position
            );

            // Falls Aktionen benötigt werden
            if ($register['action']) {
                $this->EnableAction($register['name']);
            }
        }
        
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }

    private function Registers()
    {
        return [
            ["address" => 35100, "name" => "RTC",      "type" => "U16", "unit" => "N/A", "scale" => 1,   "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Hbyte-year/Lbyte-month: 13-99/1-12", "category" => "Wechselrichter"],
            ["address" => 35103, "name" => "Vpv1",     "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV1 voltage",                        "category" => "Wechselrichter"],
            ["address" => 35104, "name" => "Ipv1",     "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV1 current",                        "category" => "Wechselrichter"],
            ["address" => 35105, "name" => "Ppv1",     "type" => "U32", "unit" => "W",   "scale" => 10,  "quantity" => 2, "readOnly" => true, "action" => false, "remark" => "PV1 Power",                          "category" => "Wechselrichter"],
            ["address" => 35107, "name" => "Vpv2",     "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "PV2 voltage",                        "category" => "Wechselrichter"],
            ["address" => 35121, "name" => "Vgrid_R",  "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid voltage",               "category" => "Smartmeter"],
            ["address" => 35122, "name" => "Igrid_R",  "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid current",               "category" => "Smartmeter"],
            ["address" => 35123, "name" => "Fgrid_R",  "type" => "U16", "unit" => "Hz",  "scale" => 100, "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "R phase Grid Frequency",             "category" => "Smartmeter"],
            ["address" => 35200, "name" => "SOC",      "type" => "U16", "unit" => "%",   "scale" => 1,   "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery state of charge",           "category" => "Batterie"],
            ["address" => 35201, "name" => "BatteryV", "type" => "U16", "unit" => "V",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery voltage",                   "category" => "Batterie"],
            ["address" => 35202, "name" => "BatteryI", "type" => "U16", "unit" => "A",   "scale" => 10,  "quantity" => 1, "readOnly" => true, "action" => false, "remark" => "Battery current",                   "category" => "Batterie"]
        ];
    }
    

    public function RequestRead()
    {
        // Spannung (Register 35107)
        $volt = $this->ReadRegister(35107, "Volt", 2, 10);
    
        // Strom (Register 35104)
        $ampere = $this->ReadRegister(35104, "Ampere", 2, 1000);
    
        // Leistung (Register 35301)
        $watt = $this->ReadRegister(35301, "Watt", 2, 10);
    
        // Energie (Register 35191)
        $kWh = $this->ReadRegister(35191, "kWh", 2, 10);
    }
    
    private function ReadRegister(int $register, string $ident, int $quantity, float $scale)
    {
        // Modbus-Nachricht erstellen
        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,           // Modbus Read Holding Register
            "Address"  => $register,   // Register-Adresse
            "Quantity" => $quantity,    // Anzahl der Register
            "Data"     => ""            // Daten leer
        ]));
    
        // Debugging: Gesendete Anfrage
        $this->SendDebug("$ident Request", json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $register,
            "Quantity" => $quantity,
            "Data"     => ""
        ]), 0);
    
        // Fehlerprüfung
        if ($response === false) {
            $this->SendDebug("$ident Error", "No response received", 0);
            return [0, 0];
        }
    
        $this->SendDebug("$ident Raw Response", bin2hex($response), 0);
    
        if (strlen($response) < 5) {
            $this->SendDebug("$ident Error", "Incomplete response received", 0);
            return [0, 0];
        }
    
        // Daten auslesen und skalieren
        $data = unpack("n2", substr($response, 2));
        $value = ($data[1] << 16 | $data[2]) / $scale; // 32-Bit kombinieren und skalieren
        $this->SendDebug("$ident Parsed", $value, 0);
    
        // Wert speichern
        SetValue($this->GetIDForIdent($ident), $value);
    
        return $data; // Rückgabe des Rohdaten-Arrays
    }
    
}