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

    public function ApplyChanges() {
        //Never delete this line!
        parent::ApplyChanges();
        
        $this->RegisterVariableFloat("Volt", "Volt", "Volt.230", 1);
        $this->RegisterVariableFloat("Ampere", "Ampere", "Ampere.16", 2);
        $this->RegisterVariableFloat("Watt", "Watt", "Watt.14490", 3);
        $this->RegisterVariableFloat("kWh", "Total kWh", "Electricity", 4);
        
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));

        $this->RegisterVariableFloat("Volt", "Volt", "~Volt", 1);
        
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
            "Quantity" => $quantity    // Anzahl der Register
        ]));
    
        // Debugging: Gesendete Anfrage
        $this->SendDebug("$ident Request", json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $register,
            "Quantity" => $quantity
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