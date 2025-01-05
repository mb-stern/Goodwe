<?php

declare(strict_types=1);

class Goodwe extends IPSModule
{
    public function Create()
    {
        parent::Create();
    
        // Verknüpfe mit dem Modbus-Gateway
        $this->ConnectParent("{A5F663AB-C400-4FE5-B207-4D67CC030564}");
    
        // Property für die Register-Liste
        $this->RegisterPropertyString('Registers', json_encode($this->GetRegisters()));
        $this->RegisterPropertyString('SelectedRegisters', json_encode([]));
    
        // Timer für zyklische Abfragen
        $this->RegisterTimer('Poller', 0, 'Goodwe_RequestRead($_IPS["TARGET"]);');
    }
    

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    
        $selectedRegisters = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
        $this->SendDebug("ApplyChanges: SelectedRegisters", json_encode($selectedRegisters), 0);
    
        foreach ($selectedRegisters as $register) {
            if ($register['selected']) {
                $ident = "Addr" . $register['address'];
                if (!$this->GetIDForIdent($ident)) {
                    $this->RegisterVariableFloat(
                        $ident,
                        $register['name'],
                        $this->GetVariableProfile($register['unit']),
                        0
                    );
                }
            }
        }
    }
    

    public function GetConfigurationForm()
    {
        $registers = json_decode($this->ReadPropertyString('Registers'), true);
        $selectedRegisters = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
    
        $values = [];
        foreach ($registers as $register) {
            $isSelected = in_array($register['address'], array_column($selectedRegisters, 'address'));
            $values[] = [
                'address'  => $register['address'],
                'name'     => $register['name'],
                'type'     => $register['type'],
                'unit'     => $register['unit'],
                'scale'    => $register['scale'],
                'selected' => $isSelected
            ];
        }
    
        return json_encode([
            'elements' => [
                [
                    'type'    => 'List',
                    'name'    => 'Registers',
                    'caption' => 'Available Registers',
                    'rowCount' => 10,
                    'add'     => false,
                    'delete'  => false,
                    'columns' => [
                        ['caption' => 'Address', 'name' => 'address', 'width' => '100px'],
                        ['caption' => 'Name', 'name' => 'name', 'width' => '200px'],
                        ['caption' => 'Type', 'name' => 'type', 'width' => '80px'],
                        ['caption' => 'Unit', 'name' => 'unit', 'width' => '80px'],
                        ['caption' => 'Scale', 'name' => 'scale', 'width' => '80px'],
                        ['caption' => 'Selected', 'name' => 'selected', 'width' => '80px', 'edit' => ['type' => 'CheckBox']]
                    ],
                    'values' => $values
                ]
            ]
        ]);
    }
    

    private function ReadRegister(int $address, string $type, float $scale)
    {
        $quantity = ($type === "U32" || $type === "S32") ? 2 : 1;

        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity
        ]));

        if ($response === false || strlen($response) < (2 * $quantity + 2)) {
            $this->SendDebug("Error", "Keine oder unvollständige Antwort für Register $address", 0);
            return 0;
        }

        $data = unpack("n*", substr($response, 2));
        $value = 0;

        switch ($type) {
            case "U16":
                $value = $data[1];
                break;
            case "S16":
                $value = ($data[1] & 0x8000) ? -((~$data[1] & 0xFFFF) + 1) : $data[1];
                break;
            case "U32":
                $value = ($data[1] << 16) | $data[2];
                break;
            case "S32":
                $combined = ($data[1] << 16) | $data[2];
                $value = ($data[1] & 0x8000) ? -((~$combined & 0xFFFFFFFF) + 1) : $combined;
                break;
            default:
                $this->SendDebug("Error", "Unbekannter Typ für Register $address: $type", 0);
        }

        return $value / $scale;
    }

    private function GetRegisters()
    {
        return [
            ["address" => 35103, "name" => "PV1 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 35104, "name" => "PV1 Current", "type" => "U16", "unit" => "A", "scale" => 10],
            ["address" => 35191, "name" => "Total PV Energy", "type" => "U32", "unit" => "kWh", "scale" => 10],
            ["address" => 35107, "name" => "PV2 Voltage", "type" => "U16", "unit" => "V", "scale" => 10],
            ["address" => 36025, "name" => "Smartmeter Power", "type" => "S32", "unit" => "W", "scale" => 1]
        ];
    }

    private function GetVariableProfile(string $unit)
    {
        switch ($unit) {
            case "V":
                return "~Volt";
            case "A":
                return "~Ampere";
            case "W":
                return "~Watt";
            case "kWh":
                return "~Electricity";
            default:
                return ""; // Fallback
        }
    }
}
