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

    private function GetVariableProfile(string $unit, float $scale)
    {
        switch ($unit) {
            case "V":
                return "~Volt";
            case "A":
                return "~Ampere";
            case "W":
                return "~Watt";
            case "Hz":
                return "~Hertz";
            case "%":
                return "~Humidity"; // Beispiel für Prozentangaben
            default:
                return ""; // Kein Profil
        }
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
        foreach ($this->Registers() as $register) {
            $value = $this->ReadRegister($register['address'], $register['type'], $register['scale']);
            SetValue($this->GetIDForIdent($register['name']), $value);
        }
    }
    
    
    private function ReadRegister(int $register, string $type, float $scale)
    {
        // Bestimme die Anzahl der Register basierend auf dem Typ
        $quantity = ($type === "U32") ? 2 : 1;

        // Anfrage senden
        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,           // Modbus Read Holding Register
            "Address"  => $register,   // Register-Adresse
            "Quantity" => $quantity,   // Anzahl der Register
            "Data"     => ""           // Daten leer
        ]));

        // Debugging: Gesendete Anfrage
        $this->SendDebug("Request for Register $register", json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $register,
            "Quantity" => $quantity,
            "Data"     => ""
        ]), 0);

        // Fehlerprüfung
        if ($response === false) {
            $this->SendDebug("Error", "No response received for Register $register", 0);
            return 0;
        }

        $this->SendDebug("Raw Response for Register $register", bin2hex($response), 0);

        if (strlen($response) < ($quantity * 2) + 3) {
            $this->SendDebug("Error", "Incomplete response for Register $register", 0);
            return 0;
        }

        // Daten auslesen
        $data = unpack("n*", substr($response, 2));
        $value = 0;

        if ($type === "U16") {
            $value = $data[1] / $scale; // Skalierung anwenden
        } elseif ($type === "U32") {
            $value = ($data[1] << 16 | $data[2]) / $scale; // 32-Bit kombinieren und skalieren
        }

        $this->SendDebug("Parsed Value for Register $register", $value, 0);
        return $value;
    }

}