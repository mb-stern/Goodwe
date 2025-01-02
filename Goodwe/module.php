<?php
class Goodwe extends IPSModule
{
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
        // Never delete this line!
        parent::ApplyChanges();
    
        // PV1 Variablen
        $this->RegisterVariableFloat("PV1Voltage", "PV1 Voltage", "~Volt", 1);
        $this->RegisterVariableFloat("PV1Current", "PV1 Current", "~Ampere", 2);
        $this->RegisterVariableFloat("PV1Power", "PV1 Power", "~Watt.14490", 3);
    
        // PV2 Variablen
        $this->RegisterVariableFloat("PV2Voltage", "PV2 Voltage", "~Volt", 4);
        $this->RegisterVariableFloat("PV2Current", "PV2 Current", "~Ampere", 5);
        $this->RegisterVariableFloat("PV2Power", "PV2 Power", "~Watt.14490", 6);
    
        // Timer-Intervall setzen
        $this->SetTimerInterval("Poller", $this->ReadPropertyInteger("Poller"));
    }
    

    public function RequestRead()
    {
        // PV1-Spannung lesen
        $addressPV1Voltage = 35103; // Register-Adresse für PV1-Spannung
        $responsePV1Voltage = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV1Voltage,
            "Quantity" => 1
        ]));
    
        if ($responsePV1Voltage !== false) {
            $data = unpack("n*", $responsePV1Voltage);
            $voltage = $data[1] / 10; // Skalierung anwenden
            SetValue($this->GetIDForIdent("PV1Voltage"), $voltage);
        }
    
        // PV1-Strom lesen
        $addressPV1Current = 35104; // Register-Adresse für PV1-Strom
        $responsePV1Current = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV1Current,
            "Quantity" => 1
        ]));
    
        if ($responsePV1Current !== false) {
            $data = unpack("n*", $responsePV1Current);
            $current = $data[1] / 10; // Skalierung anwenden
            SetValue($this->GetIDForIdent("PV1Current"), $current);
        }
    
        // PV1-Leistung lesen
        $addressPV1Power = 35105; // Register-Adresse für PV1-Leistung (32-Bit Wert)
        $responsePV1Power = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV1Power,
            "Quantity" => 2 // 32-Bit benötigt 2 Register
        ]));
    
        if ($responsePV1Power !== false) {
            $data = unpack("n*", $responsePV1Power);
            $power = ($data[1] << 16 | $data[2]) / 10; // 32-Bit kombinieren und skalieren
            SetValue($this->GetIDForIdent("PV1Power"), $power);
        }
    
        // PV2-Spannung lesen
        $addressPV2Voltage = 35107; // Register-Adresse für PV2-Spannung
        $responsePV2Voltage = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV2Voltage,
            "Quantity" => 1
        ]));
    
        if ($responsePV2Voltage !== false) {
            $data = unpack("n*", $responsePV2Voltage);
            $voltage = $data[1] / 10; // Skalierung anwenden
            SetValue($this->GetIDForIdent("PV2Voltage"), $voltage);
        }
    
        // PV2-Strom lesen
        $addressPV2Current = 35108; // Register-Adresse für PV2-Strom
        $responsePV2Current = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV2Current,
            "Quantity" => 1
        ]));
    
        if ($responsePV2Current !== false) {
            $data = unpack("n*", $responsePV2Current);
            $current = $data[1] / 10; // Skalierung anwenden
            SetValue($this->GetIDForIdent("PV2Current"), $current);
        }
    
        // PV2-Leistung lesen
        $addressPV2Power = 35109; // Register-Adresse für PV2-Leistung (32-Bit Wert)
        $responsePV2Power = $this->SendDataToParent(json_encode([
            "DataID" => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address" => $addressPV2Power,
            "Quantity" => 2 // 32-Bit benötigt 2 Register
        ]));
    
        if ($responsePV2Power !== false) {
            $data = unpack("n*", $responsePV2Power);
            $power = ($data[1] << 16 | $data[2]) / 10; // 32-Bit kombinieren und skalieren
            SetValue($this->GetIDForIdent("PV2Power"), $power);
        }
    }
    
    
}