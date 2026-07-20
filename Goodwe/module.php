<?php

class Goodwe extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString("SelectedRegisters", "[]");

        $this->RegisterPropertyInteger("PollIntervalWR", 10);

        $this->RegisterPropertyBoolean("Entladen_Max", false);
        $this->RegisterPropertyBoolean("Laden_Max", false);
        $this->RegisterPropertyBoolean("Entladen_Max_2", false);
        $this->RegisterPropertyBoolean("Laden_Max_2", false);

        $this->RegisterAttributeString("LastRawRegisters", "{}");
        $this->RegisterAttributeInteger("LastPollStarted", 0);

        $this->RegisterTimer('TimerWR', 0, 'Goodwe_FetchInverterData($_IPS[\'TARGET\']);');
    }

    public function GetCompatibleParents(): string
    {
        // Modbus-Gateway
        return '{"type": "connect", "moduleIDs": ["{A5F663AB-C400-4FE5-B207-4D67CC030564}"]}';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('TimerWR', 0);

        $this->CreateProfile();

        $selectedRegisters = json_decode(
            $this->ReadPropertyString('SelectedRegisters'),
            true
        );

        if (!is_array($selectedRegisters)) {
            $selectedRegisters = [];
        }

        $selectedMap = [];

        foreach ($selectedRegisters as $r) {
            // Kompatibilität mit eventuell älteren gespeicherten Formaten.
            if (is_string($r)) {
                $tmp = json_decode($r, true);
                if (is_array($tmp)) {
                    $r = $tmp;
                } else {
                    continue;
                }
            }

            if (!is_array($r)) {
                continue;
            }

            $addr = null;

            if (isset($r['addr'])) {
                $addr = (string)$r['addr'];
            } elseif (isset($r['address'])) {
                $addr = (string)$r['address'];
            }

            if ($addr === null || $addr === '') {
                continue;
            }

            if (!empty($r['selected'])) {
                $selectedMap[$addr] = true;
            }
        }

        foreach ($this->GetRegisters() as $r) {
            if (!isset($r['address'], $r['pos'])) {
                continue;
            }

            $ident = 'Addr' . (string)$r['address'];
            $variableID = @$this->GetIDForIdent($ident);

            if ($variableID !== false) {
                IPS_SetPosition($variableID, (int)$r['pos']);
            }
        }

        $registerCurrentIdents = [];

        foreach ($this->GetRegisters() as $r) {
            $addrKey = (string)$r['address'];

            if (!isset($selectedMap[$addrKey])) {
                continue;
            }

            foreach (['address', 'name', 'type', 'unit', 'scale', 'pos'] as $need) {
                if (!array_key_exists($need, $r)) {
                    $this->SendDebug(
                        'ApplyChanges',
                        "Fehlendes Feld '$need' für Register $addrKey",
                        0
                    );
                    continue 2;
                }
            }

            $details = $this->GetVariableDetails((string)$r['unit']);

            if ($details === null) {
                $this->SendDebug(
                    'ApplyChanges',
                    "Keine Details/Profil für Einheit '{$r['unit']}' (Addr $addrKey).",
                    0
                );
                continue;
            }

            $ident = 'Addr' . $addrKey;
            $registerCurrentIdents[] = $ident;

            $variableID = @$this->GetIDForIdent($ident);

            if ($variableID === false) {
                switch ($details['type']) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterVariableInteger(
                            $ident,
                            $r['name'],
                            $details['profile'],
                            (int)$r['pos']
                        );
                        break;

                    case VARIABLETYPE_FLOAT:
                        $this->RegisterVariableFloat(
                            $ident,
                            $r['name'],
                            $details['profile'],
                            (int)$r['pos']
                        );
                        break;

                    case VARIABLETYPE_STRING:
                        $this->RegisterVariableString(
                            $ident,
                            $r['name'],
                            $details['profile'],
                            (int)$r['pos']
                        );
                        break;

                    case VARIABLETYPE_BOOLEAN:
                        $this->RegisterVariableBoolean(
                            $ident,
                            $r['name'],
                            $details['profile'],
                            (int)$r['pos']
                        );
                        break;
                }

                $this->SendDebug(
                    'ApplyChanges',
                    "Register-Variable erstellt: $ident ({$r['name']}) Profil={$details['profile']}",
                    0
                );
            }

            // Position auch bei bereits bestehenden Variablen aktualisieren.
            $variableID = @$this->GetIDForIdent($ident);

            if ($variableID !== false) {
                IPS_SetPosition($variableID, (int)$r['pos']);
            }
        }

        /*
        * Aktionen für schreibbare Register aktivieren.
        */
        foreach ($this->GetRegisters() as $r) {
            if (empty($r['writable'])) {
                continue;
            }

            $writeIdent = 'Addr' . (string)$r['address'];
            $variableID = @$this->GetIDForIdent($writeIdent);

            if ($variableID !== false) {
                $this->EnableAction($writeIdent);
            }
        }

        /*
        * Nicht mehr ausgewählte Registervariablen entfernen.
        */
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);

            if (
                strpos($obj['ObjectIdent'], 'Addr') === 0
                && !in_array(
                    $obj['ObjectIdent'],
                    $registerCurrentIdents,
                    true
                )
            ) {
                $this->UnregisterVariable($obj['ObjectIdent']);

                $this->SendDebug(
                    'ApplyChanges',
                    "Register-Variable entfernt: {$obj['ObjectIdent']}",
                    0
                );
            }
        }

        /*
        * Berechnete Werte Batterie 1
        */

        if ($this->ReadPropertyBoolean('Laden_Max')) {
            if (!@$this->GetIDForIdent('MaxLaden')) {
                $this->RegisterVariableInteger(
                    'MaxLaden',
                    'BAT - Laden Leistung max',
                    'Goodwe.Watt',
                    223
                );
            }

            $variableID = @$this->GetIDForIdent('MaxLaden');

            if ($variableID !== false) {
                IPS_SetPosition($variableID, 223);
            }
        } else {
            if (@$this->GetIDForIdent('MaxLaden') !== false) {
                $this->UnregisterVariable('MaxLaden');

                $this->SendDebug(
                    'ApplyChanges',
                    'MaxLaden-Variable entfernt, da Laden_Max deaktiviert.',
                    0
                );
            }
        }

        if ($this->ReadPropertyBoolean('Entladen_Max')) {
            if (!@$this->GetIDForIdent('MaxEntladen')) {
                $this->RegisterVariableInteger(
                    'MaxEntladen',
                    'BAT - Entladen Leistung max',
                    'Goodwe.Watt',
                    224
                );
            }

            $variableID = @$this->GetIDForIdent('MaxEntladen');

            if ($variableID !== false) {
                IPS_SetPosition($variableID, 224);
            }
        } else {
            if (@$this->GetIDForIdent('MaxEntladen') !== false) {
                $this->UnregisterVariable('MaxEntladen');

                $this->SendDebug(
                    'ApplyChanges',
                    'MaxEntladen-Variable entfernt, da Entladen_Max deaktiviert.',
                    0
                );
            }
        }

        /*
        * Berechnete Werte Batterie 2
        */

        if ($this->ReadPropertyBoolean('Laden_Max_2')) {
            if (!@$this->GetIDForIdent('MaxLaden2')) {
                $this->RegisterVariableInteger(
                    'MaxLaden2',
                    'BAT2 - Laden Leistung max',
                    'Goodwe.Watt',
                    314
                );
            }

            $variableID = @$this->GetIDForIdent('MaxLaden2');

            if ($variableID !== false) {
                IPS_SetPosition($variableID, 314);
            }
        } else {
            if (@$this->GetIDForIdent('MaxLaden2') !== false) {
                $this->UnregisterVariable('MaxLaden2');

                $this->SendDebug(
                    'ApplyChanges',
                    'MaxLaden-Bat2-Variable entfernt, da Laden_Max_2 deaktiviert.',
                    0
                );
            }
        }

        if ($this->ReadPropertyBoolean('Entladen_Max_2')) {
            if (!@$this->GetIDForIdent('MaxEntladen2')) {
                $this->RegisterVariableInteger(
                    'MaxEntladen2',
                    'BAT2 - Entladen Leistung max',
                    'Goodwe.Watt',
                    315
                );
            }

            $variableID = @$this->GetIDForIdent('MaxEntladen2');

            if ($variableID !== false) {
                IPS_SetPosition($variableID, 315);
            }
        } else {
            if (@$this->GetIDForIdent('MaxEntladen2') !== false) {
                $this->UnregisterVariable('MaxEntladen2');

                $this->SendDebug(
                    'ApplyChanges',
                    'MaxEntladen-Bat2-Variable entfernt, da Entladen_Max_2 deaktiviert.',
                    0
                );
            }
        }

        $pollInterval = max(
            1,
            $this->ReadPropertyInteger('PollIntervalWR')
        );

        $this->SetTimerInterval(
            'TimerWR',
            $pollInterval * 1000
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug("RequestAction", "Aktion gestartet für Ident: $Ident, Wert: " . json_encode($Value), 0);

        if (strpos($Ident, 'Addr') !== 0) {
            throw new Exception("Ungültiger Ident: $Ident");
        }

        $address = (int)substr($Ident, 4);
        $register = $this->GetRegisterByAddress($address);

        if ($register === null || empty($register['writable'])) {
            throw new Exception("Register $address ist nicht schreibbar");
        }

        $scale = (float)($register['scale'] ?? 1.0);
        if ($scale == 0.0) {
            throw new Exception("Ungültige Skalierung für Register $address");
        }

        // Die IPS-Variable enthält den physikalischen Wert. Modbus erwartet den Rohwert.
        $rawValue = (int)round(((float)$Value) / $scale);

        if (isset($register['rawMin']) && $rawValue < (int)$register['rawMin']) {
            throw new Exception("Wert für Register $address ist zu klein");
        }
        if (isset($register['rawMax']) && $rawValue > (int)$register['rawMax']) {
            throw new Exception("Wert für Register $address ist zu gross");
        }

        if (!$this->WriteRegister($address, $rawValue)) {
            $this->SendDebug("RequestAction", "Fehler beim Schreiben von Register $address: Rohwert $rawValue", 0);
            return;
        }

        $details = $this->GetVariableDetails((string)$register['unit']);
        $displayValue = $Value;
        if ($details !== null) {
            switch ($details['type']) {
                case VARIABLETYPE_INTEGER:
                    $displayValue = (int)round((float)$Value);
                    break;
                case VARIABLETYPE_FLOAT:
                    $displayValue = (float)$Value;
                    break;
                case VARIABLETYPE_BOOLEAN:
                    $displayValue = (bool)$Value;
                    break;
                case VARIABLETYPE_STRING:
                    $displayValue = (string)$Value;
                    break;
            }
        }

        // Write-only-Aktionen (z. B. WR-Neustart) werden nicht zurückgelesen.
        // Die Aktionsvariable springt nach erfolgreichem Schreiben sofort wieder auf false.
        if (!empty($register['writeOnly'])) {
            $displayValue = false;
        }

        $this->SetValueIfChanged($Ident, $displayValue);
        $this->SendDebug(
            "RequestAction",
            "Register $address erfolgreich geschrieben: Anzeige=" . json_encode($displayValue) . ", Rohwert=$rawValue" . (!empty($register['writeOnly']) ? ' (Write-only)' : ''),
            0
        );
    }

    public function FetchInverterData(): void
    {
        $lockName = 'GoodwePoll_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lockName, 1)) {
            $started = $this->ReadAttributeInteger('LastPollStarted');
            $age = $started > 0 ? time() - $started : 0;
            $this->SendDebug('FetchInverterData', "Abfrage läuft bereits seit {$age} s – Timeraufruf übersprungen.", 0);
            return;
        }

        $startedAt = microtime(true);
        $this->WriteAttributeInteger('LastPollStarted', time());

        try {
            $selectedRegisters = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
            if (!is_array($selectedRegisters)) {
                $this->SendDebug('FetchInverterData', 'SelectedRegisters ist keine gültige Liste', 0);
                return;
            }

            $selectedMap = [];
            foreach ($selectedRegisters as $r) {
                if (is_array($r) && !empty($r['selected']) && isset($r['addr'])) {
                    $selectedMap[(string)$r['addr']] = true;
                }
            }
            if ($selectedMap === []) {
                $this->SendDebug('FetchInverterData', 'Keine Register ausgewählt.', 0);
                return;
            }

            $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($parentID === 0 || !IPS_InstanceExists($parentID) || IPS_GetInstance($parentID)['InstanceStatus'] !== IS_ACTIVE) {
                $this->SendDebug('FetchInverterData', 'Keine aktive Parent-Instanz verbunden.', 0);
                return;
            }

            $registers = [];
            $allRegisters = $this->GetRegisters();
            foreach ($allRegisters as $register) {
                if (isset($selectedMap[(string)$register['address']]) && empty($register['writeOnly'])) {
                    $registers[] = $register;
                }
            }

            // Für die Leistungsberechnungen benötigte BMS-Register immer im selben Block mitlesen.
            $dependencies = [];
            if ($this->ReadPropertyBoolean('Laden_Max'))      $dependencies = array_merge($dependencies, [47902, 47903]);
            if ($this->ReadPropertyBoolean('Entladen_Max'))   $dependencies = array_merge($dependencies, [47904, 47905]);
            if ($this->ReadPropertyBoolean('Laden_Max_2'))    $dependencies = array_merge($dependencies, [47920, 47921]);
            if ($this->ReadPropertyBoolean('Entladen_Max_2')) $dependencies = array_merge($dependencies, [47922, 47923]);
            $present = array_column($registers, 'address');
            foreach (array_unique($dependencies) as $dependency) {
                if (!in_array($dependency, $present, true)) {
                    foreach ($allRegisters as $candidate) {
                        if ((int)$candidate['address'] === $dependency) {
                            $registers[] = $candidate;
                            break;
                        }
                    }
                }
            }

            $blocks = $this->BuildReadBlocks($registers, 4, 100);
            $rawRegisters = [];
            $successfulBlocks = 0;

            foreach ($blocks as $block) {
                if (IPS_GetInstance($this->InstanceID)['InstanceStatus'] === IS_DELETING) {
                    break;
                }

                $response = $this->ReadRegisterBlock($block['start'], $block['count']);
                if ($response === null) {
                    $this->SendDebug('FetchInverterData', "Block {$block['start']}–" . ($block['start'] + $block['count'] - 1) . ' konnte nicht gelesen werden.', 0);
                    continue;
                }

                $successfulBlocks++;
                foreach ($response as $offset => $word) {
                    $rawRegisters[$block['start'] + $offset] = $word;
                }
            }

            $values = [];
            foreach ($registers as $register) {
                $address = (int)$register['address'];
                $rawValue = $this->DecodeRegisterValue($register, $rawRegisters);
                if ($rawValue === null) {
                    continue;
                }

                $scaledValue = $rawValue * (float)$register['scale'];
                $ident = 'Addr' . $address;
                $varID = @$this->GetIDForIdent($ident);
                if ($varID === false) {
                    continue;
                }

                $var = IPS_GetVariable($varID);
                if ($var['VariableType'] === VARIABLETYPE_FLOAT) {
                    $scaleStr = rtrim(rtrim(number_format((float)$register['scale'], 10, '.', ''), '0'), '.');
                    $dotPos = strpos($scaleStr, '.');
                    $decimals = $dotPos === false ? 0 : strlen($scaleStr) - $dotPos - 1;
                    $finalValue = round((float)$scaledValue, $decimals);
                } elseif ($var['VariableType'] === VARIABLETYPE_BOOLEAN) {
                    $finalValue = ((int)round($scaledValue)) !== 0;
                } elseif ($var['VariableType'] === VARIABLETYPE_STRING) {
                    $finalValue = (string)$scaledValue;
                } else {
                    $finalValue = (int)round($scaledValue);
                }

                $this->SetValueIfChanged($ident, $finalValue);
                $values[$ident] = $finalValue;
            }

            $this->WriteAttributeString('LastRawRegisters', json_encode($rawRegisters));
            $this->CalculateMaxPowerFromRaw($rawRegisters);

            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $this->SendDebug('FetchInverterData', json_encode([
                'source' => 'WR_Modbus_BlockRead',
                'selectedRegisters' => count($registers),
                'blocks' => count($blocks),
                'successfulBlocks' => $successfulBlocks,
                'durationMs' => $durationMs,
                'values' => $values
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0);
        } catch (Throwable $e) {
            $this->SendDebug('FetchInverterData', 'Fehler: ' . $e->getMessage(), 0);
            $this->LogMessage('Goodwe', 'Fehler bei Blockabfrage: ' . $e->getMessage());
        } finally {
            $this->WriteAttributeInteger('LastPollStarted', 0);
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function BuildReadBlocks(array $registers, int $maxGap = 4, int $maxCount = 100): array
    {
        $ranges = [];
        foreach ($registers as $register) {
            $start = (int)$register['address'];
            $length = in_array($register['type'], ['U32', 'S32'], true) ? 2 : 1;
            $ranges[] = ['start' => $start, 'end' => $start + $length - 1];
        }
        usort($ranges, fn($a, $b) => $a['start'] <=> $b['start']);

        $blocks = [];
        foreach ($ranges as $range) {
            if ($blocks === []) {
                $blocks[] = $range;
                continue;
            }
            $last = count($blocks) - 1;
            $newEnd = max($blocks[$last]['end'], $range['end']);
            $gap = $range['start'] - $blocks[$last]['end'] - 1;
            $count = $newEnd - $blocks[$last]['start'] + 1;
            if ($gap <= $maxGap && $count <= $maxCount) {
                $blocks[$last]['end'] = $newEnd;
            } else {
                $blocks[] = $range;
            }
        }

        return array_map(fn($b) => ['start' => $b['start'], 'count' => $b['end'] - $b['start'] + 1], $blocks);
    }

    private function ReadRegisterBlock(int $start, int $count): ?array
    {
        $maxAttempts = 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $requestStartedAt = microtime(true);

            $response = $this->SendDataToParent(json_encode([
                'DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}',
                'Function' => 3,
                'Address' => $start,
                'Quantity' => $count,
                'Data' => ''
            ]));

            $durationMs = (int)round((microtime(true) - $requestStartedAt) * 1000);
            $validLength = $response !== false && strlen($response) >= ($count * 2 + 2);

            if ($validLength) {
                $words = array_values(unpack('n*', substr($response, 2)) ?: []);
                if (count($words) >= $count) {
                    if ($attempt > 1) {
                        $this->SendDebug(
                            'ReadRegisterBlock',
                            "Block {$start}–" . ($start + $count - 1) . " beim {$attempt}. Versuch erfolgreich ({$durationMs} ms).",
                            0
                        );
                    }

                    return array_slice($words, 0, $count);
                }
            }

            $responseLength = $response === false ? 0 : strlen($response);
            $this->SendDebug(
                'ReadRegisterBlock',
                "Block {$start}–" . ($start + $count - 1) . " Versuch {$attempt}/{$maxAttempts} fehlgeschlagen ({$durationMs} ms, Antwortlänge {$responseLength} Byte).",
                0
            );

            if ($attempt < $maxAttempts) {
                IPS_Sleep(150);
            }
        }

        return null;
    }

    private function DecodeRegisterValue(array $register, array $rawRegisters): ?int
    {
        $address = (int)$register['address'];
        if (!array_key_exists($address, $rawRegisters)) {
            return null;
        }
        $word1 = $rawRegisters[$address] & 0xFFFF;
        switch ($register['type']) {
            case 'U16': return $word1;
            case 'S16': return $word1 > 32767 ? $word1 - 65536 : $word1;
            case 'U32':
            case 'S32':
                if (!array_key_exists($address + 1, $rawRegisters)) return null;
                $combined = (($word1 << 16) | ($rawRegisters[$address + 1] & 0xFFFF));
                if ($register['type'] === 'S32' && $combined > 2147483647) $combined -= 4294967296;
                return $combined;
        }
        return null;
    }

    private function WriteRegister(int $address, int $value): bool
    {
        $data = [
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 6,
            "Address"  => $address,
            "Quantity" => 1,
            "Data"     => bin2hex(pack("n", $value)),
        ];

        $response = $this->SendDataToParent(json_encode($data));

        if ($response === false) {
            $this->SendDebug("WriteRegister", "Fehler beim Schreiben in Register $address", 0);
            return false;
        }

        $this->SendDebug("WriteRegister", "Erfolgreich in Register $address geschrieben: $value", 0);
        return true;
    }

    public function CalculateMaxPower(): void
    {
        $raw = json_decode($this->ReadAttributeString('LastRawRegisters'), true);
        $this->CalculateMaxPowerFromRaw(is_array($raw) ? array_map('intval', $raw) : []);
    }

    private function CalculateMaxPowerFromRaw(array $raw): void
    {
        $calculations = [
            ['property' => 'Entladen_Max',   'ident' => 'MaxEntladen',  'v' => 47904, 'a' => 47905, 'caption' => 'Entladen Max'],
            ['property' => 'Laden_Max',      'ident' => 'MaxLaden',     'v' => 47902, 'a' => 47903, 'caption' => 'Laden Max'],
            ['property' => 'Entladen_Max_2', 'ident' => 'MaxEntladen2', 'v' => 47922, 'a' => 47923, 'caption' => 'Entladen Max BAT2'],
            ['property' => 'Laden_Max_2',    'ident' => 'MaxLaden2',    'v' => 47920, 'a' => 47921, 'caption' => 'Laden Max BAT2'],
        ];
        foreach ($calculations as $calc) {
            if (!$this->ReadPropertyBoolean($calc['property']) || !isset($raw[$calc['v']], $raw[$calc['a']])) continue;
            $voltage = $this->Signed16((int)$raw[$calc['v']]) * 0.1;
            $current = $this->Signed16((int)$raw[$calc['a']]) * 0.1;
            $power = (int)round($voltage * $current);
            $this->SetValueIfChanged($calc['ident'], $power);
            $this->SendDebug('CalculateMaxPower', "{$calc['caption']}: {$voltage} V × {$current} A = {$power} W", 0);
        }
    }

    private function Signed16(int $value): int
    {
        $value &= 0xFFFF;
        return $value > 32767 ? $value - 65536 : $value;
    }

    private function ReadRegisterValue(int $address, float $scale = 1.0)
    {
        $quantity = 1;

        $response = $this->SendDataToParent(json_encode([
            "DataID"   => "{E310B701-4AE7-458E-B618-EC13A1A6F6A8}",
            "Function" => 3,
            "Address"  => $address,
            "Quantity" => $quantity,
            "Data"     => ""
        ]));

        if ($response === false || strlen($response) < 4) {
            $this->SendDebug("ReadRegisterValue", "Keine Antwort oder zu kurze Antwort für Register $address", 0);
            return null;
        }

        $data  = unpack("n*", substr($response, 2));
        $value = $data[1];

        if ($value & 0x8000) {
            $value = -((~$value & 0xFFFF) + 1);
        }

        return $value * $scale;
    }

    public function GetConfigurationForm(): string
    {
        $all = $this->GetRegisters();

        $selected = json_decode($this->ReadPropertyString("SelectedRegisters"), true);
        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedMap = [];
        foreach ($selected as $sr) {
            if (is_string($sr)) {
                $tmp = json_decode($sr, true);
                if (is_array($tmp)) {
                    $sr = $tmp;
                }
            }

            if (!is_array($sr)) {
                continue;
            }

            $addr = null;
            if (isset($sr['addr'])) {
                $addr = (string)$sr['addr'];
            } elseif (isset($sr['address'])) {
                $addr = (string)$sr['address'];
            }

            if ($addr === null || $addr === '') {
                continue;
            }

            $selectedMap[$addr] = (bool)($sr['selected'] ?? false);
        }

        $values = array_map(function ($r) use ($selectedMap) {
            $addr = (string)$r['address'];
            return [
                "addr"            => $addr,
                "selected"        => $selectedMap[$addr] ?? false,
                "address_display" => $addr,
                "name"            => $r['name'],
            ];
        }, $all);

        return json_encode([
            "elements" => [
                [
                    "type"                        => "List",
                    "name"                        => "SelectedRegisters",
                    "caption"                     => "Register auswählen",
                    "rowCount"                    => 15,
                    "add"                         => false,
                    "delete"                      => false,
                    "loadValuesFromConfiguration" => false,
                    "columns"                     => [
                        [
                            "caption" => "",
                            "name"    => "addr",
                            "width"   => "0px",
                            "visible" => false,
                            "save"    => true
                        ],
                        [
                            "caption" => "Auswählen",
                            "name"    => "selected",
                            "width"   => "120px",
                            "save"    => true,
                            "edit"    => ["type" => "CheckBox"]
                        ],
                        [
                            "caption" => "Adresse",
                            "name"    => "address_display",
                            "width"   => "110px"
                        ],
                        [
                            "caption" => "Name",
                            "name"    => "name",
                            "width"   => "auto"
                        ]
                    ],
                    "values" => $values
                ],
                [
                    "type"    => "IntervalBox",
                    "name"    => "PollIntervalWR",
                    "caption" => "Sekunden",
                    "suffix"  => "s"
                ],
                [
                    "type"    => "ExpansionPanel",
                    "caption" => "Zusätzliche Werte berechnen",
                    "items"   => [
                        ["type" => "CheckBox", "name" => "Entladen_Max",   "caption" => "Maximal mögliche Leistung für das Entladen des Speichers 1 berechnen"],
                        ["type" => "CheckBox", "name" => "Laden_Max",      "caption" => "Maximal mögliche Leistung für das Laden des Speichers 1 berechnen"],
                        ["type" => "CheckBox", "name" => "Entladen_Max_2", "caption" => "Maximal mögliche Leistung für das Entladen des Speichers 2 berechnen"],
                        ["type" => "CheckBox", "name" => "Laden_Max_2",    "caption" => "Maximal mögliche Leistung für das Laden des Speichers 2 berechnen"]
                    ]
                ]
            ],
            "actions" => [
                [
                    "type"    => "Button",
                    "caption" => "Werte lesen",
                    "onClick" => 'Goodwe_FetchInverterData($id);'
                ],
                [
                    "type" => "Label",
                    "caption" => "Sag danke und unterstütze den Modulentwickler:"
                ],
                [
                    "type" => "RowLayout",
                    "items" => [
                        [
                            "type" => "Image",
                            "onClick" => "echo 'https://paypal.me/mbstern';",
                            "image" => "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                        ],
                        [
                            "type" => "Label",
                            "caption" => ""
                        ]
                    ]
                ]
            ]
        ]);
    }

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        $vid = @$this->GetIDForIdent($Ident);
        if ($vid === false) {
            return;
        }

        $var = IPS_GetVariable($vid);

        switch ($var['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $new = (bool)$Value;
                $old = (bool)GetValue($vid);
                break;

            case VARIABLETYPE_INTEGER:
                $new = (int)$Value;
                $old = (int)GetValue($vid);
                break;

            case VARIABLETYPE_FLOAT:
                $new = (float)$Value;
                $old = (float)GetValue($vid);
                break;

            case VARIABLETYPE_STRING:
            default:
                $new = (string)$Value;
                $old = (string)GetValue($vid);
                break;
        }

        if ($old !== $new) {
            $this->SetValue($Ident, $new);
        }
    }

    private function GetVariableDetails(string $unit): ?array
    {
        switch ($unit) {
            case "V":
                return ["profile" => "~Volt", "type" => VARIABLETYPE_FLOAT];
            case "A":
                return ["profile" => "~Ampere", "type" => VARIABLETYPE_FLOAT];
            case "W":
                return ["profile" => "Goodwe.Watt", "type" => VARIABLETYPE_INTEGER];
            case "Hz":
                return ["profile" => "~Hertz", "type" => VARIABLETYPE_FLOAT];
            case "bool":
                return ["profile" => "~Switch", "type" => VARIABLETYPE_BOOLEAN];
            case "work_mode":
                return ["profile" => "Goodwe.WorkMode", "type" => VARIABLETYPE_INTEGER];
            case "grid_mode":
                return ["profile" => "Goodwe.GridMode", "type" => VARIABLETYPE_INTEGER];
            case "raw":
                return ["profile" => "", "type" => VARIABLETYPE_INTEGER];
            case "dur":
                return ["profile" => "~Duration", "type" => VARIABLETYPE_INTEGER];
            case "kWh":
                return ["profile" => "~Electricity", "type" => VARIABLETYPE_FLOAT];
            case "kW":
                return ["profile" => "~Power", "type" => VARIABLETYPE_FLOAT];
            case "KΩ":
                return ["profile" => "Goodwe.kOhm", "type" => VARIABLETYPE_INTEGER];
            case "°C":
                return ["profile" => "~Temperature", "type" => VARIABLETYPE_FLOAT];
            case "%":
                return ["profile" => "Goodwe.Percent", "type" => VARIABLETYPE_INTEGER];
            case "ems":
                return ["profile" => "Goodwe.EMSPowerMode", "type" => VARIABLETYPE_INTEGER];
            case "watt_ems":
                return ["profile" => "Goodwe.WattEMS", "type" => VARIABLETYPE_INTEGER];
            case "mode":
                return ["profile" => "Goodwe.Mode", "type" => VARIABLETYPE_INTEGER];
            case "wb_status":
                return ["profile" => "Goodwe.WallboxStatus", "type" => VARIABLETYPE_INTEGER];
            case "wb_phase":
                return ["profile" => "Goodwe.WallboxPhaseSwitch", "type" => VARIABLETYPE_INTEGER];
            case "wb_charge_mode":
                return ["profile" => "Goodwe.WallboxChargeMode", "type" => VARIABLETYPE_INTEGER];
            case "wb_onoff":
                return ["profile" => "Goodwe.WallboxOnOff", "type" => VARIABLETYPE_INTEGER];
            case "wb_connection":
                return ["profile" => "Goodwe.WallboxConnection", "type" => VARIABLETYPE_INTEGER];
            case "wb_power_spec":
                return ["profile" => "Goodwe.WallboxPowerSpec", "type" => VARIABLETYPE_INTEGER];
            case "wb_type":
                return ["profile" => "Goodwe.WallboxType", "type" => VARIABLETYPE_INTEGER];
            case "wb_source":
                return ["profile" => "Goodwe.WallboxEnergySource", "type" => VARIABLETYPE_INTEGER];
            case "String":
                return ["profile" => "~String", "type" => VARIABLETYPE_STRING];
            default:
                return null;
        }
    }

    private function CreateProfile()
    {
        if (!IPS_VariableProfileExists('Goodwe.EMSPowerMode')) {
            IPS_CreateVariableProfile('Goodwe.EMSPowerMode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '0',  'Stoped',          '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '1',  'Auto',            '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '2',  'Charge-PV',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '3',  'Discharge+PV',    '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '4',  'Import-AC',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '5',  'Export-AC',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '6',  'Conserve',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '7',  'Off-Grid',        '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '8',  'Battery-Standby', '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '9',  'Buy-Power',       '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '10', 'Sell-Power',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '11', 'Charge-BAT',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.EMSPowerMode', '12', 'Discharge-BAT',   '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.EMSPowerMode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Mode')) {
            IPS_CreateVariableProfile('Goodwe.Mode', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '0', 'keine Batterie',      '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '1', 'Standby',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '2', 'entlädt',             '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '3', 'lädt',                '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '4', 'warten auf Laden',    '', -1);
            IPS_SetVariableProfileAssociation('Goodwe.Mode', '5', 'warten auf Entladen', '', -1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Mode', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Watt')) {
            IPS_CreateVariableProfile('Goodwe.Watt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Watt', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.Watt', 0);
            IPS_SetVariableProfileValues('Goodwe.Watt', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Watt', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.WattEMS')) {
            IPS_CreateVariableProfile('Goodwe.WattEMS', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.WattEMS', '', ' W');
            IPS_SetVariableProfileDigits('Goodwe.WattEMS', 0);
            IPS_SetVariableProfileValues('Goodwe.WattEMS', 0, 10000, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.WattEMS', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.Percent')) {
            IPS_CreateVariableProfile('Goodwe.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.Percent', '', ' %');
            IPS_SetVariableProfileDigits('Goodwe.Percent', 0);
            IPS_SetVariableProfileValues('Goodwe.Percent', 0, 100, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.Percent', 0);
        }
        if (!IPS_VariableProfileExists('Goodwe.kOhm')) {
            IPS_CreateVariableProfile('Goodwe.kOhm', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('Goodwe.kOhm', '', ' KΩ');
            IPS_SetVariableProfileDigits('Goodwe.kOhm', 0);
            IPS_SetVariableProfileValues('Goodwe.kOhm', 0, 0, 1);
            $this->SendDebug('CreateProfile', 'Profil erstellt: Goodwe.kOhm', 0);
        }

        $this->CreateAssociationProfile('Goodwe.WorkMode', [0 => 'Selbstverbrauch', 1 => 'Inselbetrieb', 2 => 'Backup', 3 => 'Wirtschaftlich', 4 => 'Peak-Shaving', 5 => 'Erw. Selbstverbrauch']);
        $this->CreateAssociationProfile('Goodwe.GridMode', [0 => 'Warten', 1 => 'Einspeisung', 2 => 'Einspeisung begrenzt', 3 => 'Entsättigung', 4 => 'PV-Limit', 5 => 'Reaktiv', 6 => 'Blindleistung', 7 => 'Abgeschaltet', 8 => 'PV-Optimierung', 9 => 'ECO', 10 => 'HW-Schutz', 11 => 'Fehler', 17 => 'Bypass', 18 => 'Inselbetrieb']);

        $this->CreateAssociationProfile('Goodwe.WallboxStatus', [
            0 => 'Frei (nicht verbunden)',
            1 => 'Frei (verbunden)',
            2 => 'Fahrzeug-Handschlag',
            3 => 'Lädt',
            4 => 'Laden beendet',
            5 => 'Störung',
            6 => 'Zeitplan startet',
            7 => 'Wartung',
            8 => 'Start fehlgeschlagen',
            9 => 'Systemupdate',
            10 => 'Unterbrochen: zu wenig PV/Batterie'
        ]);
        $this->CreateAssociationProfile('Goodwe.WallboxPhaseSwitch', [0 => 'Aus', 1 => 'Ein']);
        $this->CreateAssociationProfile('Goodwe.WallboxChargeMode', [
            0 => 'Sofortladen',
            1 => 'PV-Überschussladen',
            2 => 'PV + Batterie'
        ]);
        $this->CreateAssociationProfile('Goodwe.WallboxOnOff', [1 => 'Laden aus', 2 => 'Laden ein']);
        $this->CreateAssociationProfile('Goodwe.WallboxConnection', [
            0 => 'Getrennt',
            1 => 'Teilweise verbunden',
            2 => 'Verbunden'
        ]);
        $this->CreateAssociationProfile('Goodwe.WallboxPowerSpec', [0 => '7 kW', 1 => '11 kW', 2 => '22 kW']);
        $this->CreateAssociationProfile('Goodwe.WallboxType', [0 => 'Dreiphasig', 1 => 'Einphasig']);
        $this->CreateAssociationProfile('Goodwe.WallboxEnergySource', [
            0 => 'Keine',
            1 => 'Netz',
            2 => 'PV',
            3 => 'Netz + PV',
            4 => 'Batterie',
            5 => 'Netz + Batterie',
            6 => 'PV + Batterie',
            7 => 'Netz + PV + Batterie'
        ]);
    }

    private function CreateAssociationProfile(string $name, array $associations): void
    {
        if (IPS_VariableProfileExists($name)) {
            return;
        }

        IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        foreach ($associations as $value => $caption) {
            IPS_SetVariableProfileAssociation($name, (int)$value, (string)$caption, '', -1);
        }
        $this->SendDebug('CreateProfile', 'Profil erstellt: ' . $name, 0);
    }

    private function GetRegisterByAddress(int $address): ?array
    {
        foreach ($this->GetRegisters() as $register) {
            if ((int)$register['address'] === $address) {
                return $register;
            }
        }

        return null;
    }

    private function GetRegisters()
    {
        $registers = [
            // Wechselrichter
            ["address" => 35103, "name" => "WR - Spannung String 1",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 100],
            ["address" => 35104, "name" => "WR - Strom String 1",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 101],
            ["address" => 35105, "name" => "WR - Leistung String 1",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 102],
            ["address" => 35107, "name" => "WR - Spannung String 2",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 103],
            ["address" => 35108, "name" => "WR - Strom String 2",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 104],
            ["address" => 35109, "name" => "WR - Leistung String 2",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 105],
            ["address" => 35111, "name" => "WR - Spannung String 3",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 106],
            ["address" => 35112, "name" => "WR - Strom String 3",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 107],
            ["address" => 35113, "name" => "WR - Leistung String 3",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 108],
            ["address" => 35115, "name" => "WR - Spannung String 4",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 109],
            ["address" => 35116, "name" => "WR - Strom String 4",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 110],
            ["address" => 35117, "name" => "WR - Leistung String 4",       "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 111],
            ["address" => 35304, "name" => "WR - Spannung String 5",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 112],
            ["address" => 35305, "name" => "WR - Strom String 5",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 113],
            ["address" => 35306, "name" => "WR - Spannung String 6",       "type" => "U16", "unit" => "V",        "scale" => 0.1, "pos" => 114],
            ["address" => 35307, "name" => "WR - Strom String 6",          "type" => "U16", "unit" => "A",        "scale" => 0.1, "pos" => 115],
            ["address" => 35174, "name" => "WR - Temperatur",              "type" => "S16", "unit" => "°C",       "scale" => 0.1, "pos" => 116],
            ["address" => 35191, "name" => "WR - Erzeugung Gesamt",        "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 117],
            ["address" => 35193, "name" => "WR - Erzeugung Tag",           "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 118],
            ["address" => 35301, "name" => "WR - Leistung Gesamt",         "type" => "U32", "unit" => "W",        "scale" => 1,   "pos" => 119],
            ["address" => 35337, "name" => "WR - P MPPT1",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 120],
            ["address" => 35338, "name" => "WR - P MPPT2",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 121],
            ["address" => 35339, "name" => "WR - P MPPT3",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 122],
            ["address" => 35340, "name" => "WR - P MPPT4",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 123],
            ["address" => 35341, "name" => "WR - P MPPT5",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 124],
            ["address" => 35342, "name" => "WR - P MPPT6",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 125],
            ["address" => 35343, "name" => "WR - P MPPT7",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 126],
            ["address" => 35344, "name" => "WR - P MPPT8",                 "type" => "S16", "unit" => "W",        "scale" => 1,   "pos" => 127],
            ["address" => 35345, "name" => "WR - I MPPT1",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 128],
            ["address" => 35346, "name" => "WR - I MPPT2",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 129],
            ["address" => 35347, "name" => "WR - I MPPT3",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 130],
            ["address" => 35348, "name" => "WR - I MPPT4",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 131],
            ["address" => 35349, "name" => "WR - I MPPT5",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 132],
            ["address" => 35350, "name" => "WR - I MPPT6",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 133],
            ["address" => 35351, "name" => "WR - I MPPT7",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 134],
            ["address" => 35352, "name" => "WR - I MPPT8",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 135],
            ["address" => 35365, "name" => "WR - Isolationswiderstand",    "type" => "U16", "unit" => "KΩ",       "scale" => 0.1, "pos" => 136],
            ["address" => 35121, "name" => "WR - Netzspannung L1",             "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 137],
            ["address" => 35122, "name" => "WR - Netzstrom L1",                "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 138],
            ["address" => 35123, "name" => "WR - Netzfrequenz L1",             "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 139],
            ["address" => 35124, "name" => "WR - Netzleistung L1",             "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 140],
            ["address" => 35126, "name" => "WR - Netzspannung L2",             "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 141],
            ["address" => 35127, "name" => "WR - Netzstrom L2",                "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 142],
            ["address" => 35128, "name" => "WR - Netzfrequenz L2",             "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 143],
            ["address" => 35129, "name" => "WR - Netzleistung L2",             "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 144],
            ["address" => 35131, "name" => "WR - Netzspannung L3",             "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 145],
            ["address" => 35132, "name" => "WR - Netzstrom L3",                "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 146],
            ["address" => 35133, "name" => "WR - Netzfrequenz L3",             "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 147],
            ["address" => 35134, "name" => "WR - Netzleistung L3",             "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 148],
            ["address" => 35136, "name" => "WR - Netzmodus",                   "type" => "U16", "unit" => "grid_mode", "scale" => 1, "pos" => 149],
            ["address" => 35137, "name" => "WR - Inverter Gesamtleistung",     "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 150],
            ["address" => 35139, "name" => "WR - AC Wirkleistung",             "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 151],
            ["address" => 35175, "name" => "WR - Modultemperatur",              "type" => "S16", "unit" => "°C",        "scale" => 0.1, "pos" => 152],
            ["address" => 35176, "name" => "WR - Kühlkörpertemperatur",         "type" => "S16", "unit" => "°C",        "scale" => 0.1, "pos" => 153],
            ["address" => 35197, "name" => "WR - Betriebsstunden",              "type" => "U32", "unit" => "dur",       "scale" => 1, "pos" => 154],
            ["address" => 35199, "name" => "WR - Einspeisung Tag",              "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 155],
            ["address" => 35202, "name" => "WR - Netzbezug Tag",                "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 156],
            ["address" => 35203, "name" => "WR - Last Gesamt",                  "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 157],
            ["address" => 35205, "name" => "WR - Last Tag",                     "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 158],
            ["address" => 45220, "name" => "WR - Neustart",                     "type" => "U16", "unit" => "bool",      "scale" => 1, "pos" => 159, "writable" => true, "writeOnly" => true, "rawMin" => 0, "rawMax" => 1],
            ["address" => 47000, "name" => "WR - Betriebsmodus",                "type" => "U16", "unit" => "work_mode", "scale" => 1, "pos" => 160, "writable" => true, "rawMin" => 0, "rawMax" => 5],
            ["address" => 47017, "name" => "WR - Cloud-Verbindung Rohwert",      "type" => "U16", "unit" => "bool",      "scale" => 1, "pos" => 161, "writable" => true, "rawMin" => 0, "rawMax" => 1],
            ["address" => 47509, "name" => "WR - Einspeisung aktiv",             "type" => "U16", "unit" => "bool",      "scale" => 1, "pos" => 162, "writable" => true, "rawMin" => 0, "rawMax" => 1],
            ["address" => 47510, "name" => "WR - Einspeisegrenze",               "type" => "U16", "unit" => "W",         "scale" => 1, "pos" => 163, "writable" => true, "rawMin" => 0, "rawMax" => 34500],

            // Batterie 1
            ["address" => 35182, "name" => "BAT - Leistung",               "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 200],
            ["address" => 35184, "name" => "BAT - Mode",                   "type" => "U16", "unit" => "mode",     "scale" => 1,   "pos" => 201],
            ["address" => 35206, "name" => "BAT - Laden",                  "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 202],
            ["address" => 35209, "name" => "BAT - Entladen",               "type" => "U32", "unit" => "kWh",      "scale" => 0.1, "pos" => 203],
            ["address" => 37003, "name" => "BAT - Temperatur",             "type" => "U16", "unit" => "°C",       "scale" => 0.1, "pos" => 204],
            ["address" => 45356, "name" => "BAT - Min SOC online",         "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 205, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 45358, "name" => "BAT - Min SOC offline",        "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 206, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 47511, "name" => "BAT - EMSPowerMode",           "type" => "U16", "unit" => "ems",      "scale" => 1,   "pos" => 207, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 47512, "name" => "BAT - EMSPowerSet",            "type" => "U16", "unit" => "watt_ems", "scale" => 1,   "pos" => 208, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 47902, "name" => "BAT - Laden Spannung max",     "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 209],
            ["address" => 47903, "name" => "BAT - Laden Strom max",        "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 210],
            ["address" => 47904, "name" => "BAT - Entladen Spannung max",  "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 211],
            ["address" => 47905, "name" => "BAT - Entladen Strom max",     "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 212],
            ["address" => 47906, "name" => "BAT - Spannung",               "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 213],
            ["address" => 47907, "name" => "BAT - Strom",                  "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 214],
            ["address" => 47908, "name" => "BAT - SOC",                    "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 215],
            ["address" => 47909, "name" => "BAT - SOH",                    "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 216],
            ["address" => 35208, "name" => "BAT - Laden Tag",                   "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 217],
            ["address" => 35211, "name" => "BAT - Entladen Tag",                "type" => "U32", "unit" => "kWh",       "scale" => 0.1, "pos" => 218],
            ["address" => 47505, "name" => "BAT - EMS-Steuerung aktiv",         "type" => "U16", "unit" => "bool",      "scale" => 1, "pos" => 219, "writable" => true, "rawMin" => 0, "rawMax" => 1],
            ["address" => 47910, "name" => "BAT - BMS Temperatur",               "type" => "S16", "unit" => "°C",        "scale" => 0.1, "pos" => 220],
            ["address" => 47911, "name" => "BAT - BMS Warnung",                 "type" => "U16", "unit" => "raw",         "scale" => 1, "pos" => 221],
            ["address" => 47913, "name" => "BAT - BMS Alarm",                   "type" => "U16", "unit" => "raw",         "scale" => 1, "pos" => 222],

            // Batterie 2
            ["address" => 35264, "name" => "BAT2 - Leistung",              "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 300],
            ["address" => 35266, "name" => "BAT2 - Mode",                  "type" => "U16", "unit" => "mode",     "scale" => 1,   "pos" => 301],
            ["address" => 39001, "name" => "BAT2 - Temperatur",            "type" => "U16", "unit" => "°C",       "scale" => 0.1, "pos" => 302],
            ["address" => 45381, "name" => "BAT2 - Min SOC online",        "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 303, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 45383, "name" => "BAT2 - Min SOC offline",       "type" => "U16", "unit" => "%",        "scale" => 1,   "pos" => 304, "writable" => true, "rawMin" => 0, "rawMax" => 65535],
            ["address" => 47920, "name" => "BAT2 - Laden Spannung max",    "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 305],
            ["address" => 47921, "name" => "BAT2 - Laden Strom max",       "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 306],
            ["address" => 47922, "name" => "BAT2 - Entladen Spannung max", "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 307],
            ["address" => 47923, "name" => "BAT2 - Entladen Strom max",    "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 308],
            ["address" => 47924, "name" => "BAT2 - Spannung",              "type" => "S16", "unit" => "V",        "scale" => 0.1, "pos" => 309],
            ["address" => 47925, "name" => "BAT2 - Strom",                 "type" => "S16", "unit" => "A",        "scale" => 0.1, "pos" => 310],
            ["address" => 47926, "name" => "BAT2 - SOC",                   "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 311],
            ["address" => 47927, "name" => "BAT2 - SOH",                   "type" => "S16", "unit" => "%",        "scale" => 1,   "pos" => 312],
            ["address" => 47928, "name" => "BAT2 - BMS Temperatur",              "type" => "S16", "unit" => "°C",        "scale" => 0.1, "pos" => 313],

            // Backup
            ["address" => 35145, "name" => "Backup - Spannung L1",              "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 400],
            ["address" => 35146, "name" => "Backup - Strom L1",                 "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 401],
            ["address" => 35147, "name" => "Backup - Frequenz L1",              "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 402],
            ["address" => 35149, "name" => "Backup - Leistung L1",              "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 403],
            ["address" => 35151, "name" => "Backup - Spannung L2",              "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 404],
            ["address" => 35152, "name" => "Backup - Strom L2",                 "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 405],
            ["address" => 35153, "name" => "Backup - Frequenz L2",              "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 406],
            ["address" => 35155, "name" => "Backup - Leistung L2",              "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 407],
            ["address" => 35157, "name" => "Backup - Spannung L3",              "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 408],
            ["address" => 35158, "name" => "Backup - Strom L3",                 "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 409],
            ["address" => 35159, "name" => "Backup - Frequenz L3",              "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 410],
            ["address" => 35161, "name" => "Backup - Leistung L3",              "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 411],
            ["address" => 35169, "name" => "Backup - Gesamtleistung",           "type" => "S32", "unit" => "W",         "scale" => 1, "pos" => 412],
            ["address" => 45252, "name" => "Backup - Aktiv",                    "type" => "U16", "unit" => "bool",      "scale" => 1, "pos" => 413],

            // Wallbox
            ["address" => 10009, "name" => "WB - Spannung L1",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 500],
            ["address" => 10010, "name" => "WB - Spannung L2",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 501],
            ["address" => 10011, "name" => "WB - Spannung L3",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 502],
            ["address" => 10012, "name" => "WB - Strom L1",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 503],
            ["address" => 10013, "name" => "WB - Strom L2",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 504],
            ["address" => 10014, "name" => "WB - Strom L3",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 505],
            ["address" => 10015, "name" => "WB - Ladeleistung",                   "type" => "U16", "unit" => "W",              "scale" => 100,   "pos" => 506],
            ["address" => 10016, "name" => "WB - Energie aktuelle Ladung",         "type" => "U16", "unit" => "kWh",            "scale" => 0.1,   "pos" => 507],
            ["address" => 10017, "name" => "WB - Status",                         "type" => "U16", "unit" => "wb_status",      "scale" => 1,     "pos" => 508],
            ["address" => 10023, "name" => "WB - Automatische Phasenumschaltung",  "type" => "U16", "unit" => "wb_phase",       "scale" => 1,     "pos" => 509,  "writable" => true, "rawMin" => 0,  "rawMax" => 1],
            ["address" => 10029, "name" => "WB - Sollleistung",                   "type" => "U16", "unit" => "W",              "scale" => 100,   "pos" => 510, "writable" => true, "rawMin" => 14, "rawMax" => 220],
            ["address" => 10030, "name" => "WB - Batterie Entladegrenze",          "type" => "U16", "unit" => "%",              "scale" => 1,     "pos" => 511, "writable" => true, "rawMin" => 0,  "rawMax" => 100],
            ["address" => 10032, "name" => "WB - Lademodus",                      "type" => "U16", "unit" => "wb_charge_mode", "scale" => 1,     "pos" => 512, "writable" => true, "rawMin" => 0,  "rawMax" => 2],
            ["address" => 10058, "name" => "WB - Leistungsklasse",                 "type" => "U16", "unit" => "wb_power_spec",  "scale" => 1,     "pos" => 513],
            ["address" => 10059, "name" => "WB - Ausführung",                     "type" => "U16", "unit" => "wb_type",        "scale" => 1,     "pos" => 514],
            ["address" => 10060, "name" => "WB - Laden Ein/Aus",                   "type" => "U16", "unit" => "wb_onoff",       "scale" => 1,     "pos" => 515, "writable" => true, "rawMin" => 1,  "rawMax" => 2],
            ["address" => 10065, "name" => "WB - Energie gesamt",                  "type" => "U32", "unit" => "kWh",            "scale" => 0.1,   "pos" => 516],
            ["address" => 10075, "name" => "WB - Fahrzeugverbindung",              "type" => "U16", "unit" => "wb_connection",  "scale" => 1,     "pos" => 517],
            ["address" => 10108, "name" => "WB - Energiequelle",                   "type" => "U16", "unit" => "wb_source",      "scale" => 1,     "pos" => 518],

            // Smartmeter
            ["address" => 36019, "name" => "SM - Leistung PH1",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 600],
            ["address" => 36021, "name" => "SM - Leistung PH2",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 601],
            ["address" => 36023, "name" => "SM - Leistung PH3",            "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 602],
            ["address" => 36025, "name" => "SM - Leistung gesamt",         "type" => "S32", "unit" => "W",        "scale" => 1,   "pos" => 603],
            ["address" => 36014, "name" => "SM - Netzfrequenz",                 "type" => "U16", "unit" => "Hz",        "scale" => 0.01, "pos" => 604],
            ["address" => 36052, "name" => "SM - Spannung L1",                  "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 605],
            ["address" => 36053, "name" => "SM - Spannung L2",                  "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 606],
            ["address" => 36054, "name" => "SM - Spannung L3",                  "type" => "U16", "unit" => "V",         "scale" => 0.1, "pos" => 607],
            ["address" => 36055, "name" => "SM - Strom L1",                     "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 608],
            ["address" => 36056, "name" => "SM - Strom L2",                     "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 609],
            ["address" => 36057, "name" => "SM - Strom L3",                     "type" => "U16", "unit" => "A",         "scale" => 0.1, "pos" => 610],
        ];

        return $registers;
    }
}