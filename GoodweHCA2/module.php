<?php

class GoodWeHCA2 extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('SelectedRegisters', '[]');
        $this->RegisterPropertyInteger('PollInterval', 5);
        $this->RegisterPropertyString('WallboxModel', 'GW11K-HCA-20');

        // Korrektur der Sollleistung:
        //  0 % = unverändert
        // -5 % = 5 % weniger an die Wallbox übertragen
        // +5 % = 5 % mehr an die Wallbox übertragen
        // Beim Rücklesen wird die Korrektur invers gerechnet, damit in
        // Symcon weiterhin der gewünschte Sollwert angezeigt wird.
        $this->RegisterPropertyFloat('SollleistungKorrekturProzent', 0.0);

        $this->RegisterAttributeInteger('LastPollStarted', 0);

        $this->RegisterTimer(
            'TimerWallbox',
            0,
            'GoodWeHCA2_FetchWallboxData($_IPS[\'TARGET\']);'
        );
    }

    public function GetCompatibleParents(): string
    {
        // Modbus-Gateway
        return '{"type": "connect", "moduleIDs": ["{A5F663AB-C400-4FE5-B207-4D67CC030564}"]}';
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('TimerWallbox', 0);
        $this->CreateProfiles();

        $rawSelected = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
        if (!is_array($rawSelected)) {
            $rawSelected = [];
        }

        $selectedMap = [];
        foreach ($rawSelected as $row) {
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                if (is_array($decoded)) {
                    $row = $decoded;
                }
            }

            if (!is_array($row)) {
                continue;
            }

            $address = $row['addr'] ?? $row['address'] ?? null;
            if ($address === null || $address === '') {
                continue;
            }

            $selectedMap[(string)$address] = (bool)($row['selected'] ?? false);
        }

        $normalized = [];
        foreach ($this->GetRegisters() as $register) {
            $address = (string)$register['address'];
            $normalized[] = [
                'addr'     => $address,
                'selected' => $selectedMap[$address] ?? true
            ];
        }

        if (json_encode($rawSelected) !== json_encode($normalized)) {
            IPS_SetProperty($this->InstanceID, 'SelectedRegisters', json_encode($normalized));
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

        $selectedRegisters = [];
        foreach ($normalized as $row) {
            if (!empty($row['selected'])) {
                $selectedRegisters[(string)$row['addr']] = true;
            }
        }

        $activeIdents = [];

        foreach ($this->GetRegisters() as $register) {
            $address = (string)$register['address'];
            $ident = 'Addr' . $address;

            if (!isset($selectedRegisters[$address])) {
                continue;
            }

            $details = $this->GetVariableDetails((string)$register['unit']);
            if ($details === null) {
                continue;
            }

            $activeIdents[] = $ident;

            if (@$this->GetIDForIdent($ident) === false) {
                switch ($details['type']) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterVariableInteger(
                            $ident,
                            $register['name'],
                            $details['profile'],
                            (int)$register['pos']
                        );
                        break;

                    case VARIABLETYPE_FLOAT:
                        $this->RegisterVariableFloat(
                            $ident,
                            $register['name'],
                            $details['profile'],
                            (int)$register['pos']
                        );
                        break;
                }
            }

            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID !== false) {
                IPS_SetPosition($variableID, (int)$register['pos']);

                // Bei bereits vorhandenen Variablen wird das Profil durch
                // RegisterVariable* nicht automatisch ausgetauscht.
                // Deshalb das aktuelle Profil explizit setzen.
                if ($details['profile'] !== '') {
                    IPS_SetVariableCustomProfile(
                        $variableID,
                        $details['profile']
                    );
                }
            }

            if (!empty($register['writable']) && $variableID !== false) {
                $this->EnableAction($ident);
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            $ident = (string)$object['ObjectIdent'];

            if (
                strpos($ident, 'Addr') === 0
                && !in_array($ident, $activeIdents, true)
            ) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->SetTimerInterval(
            'TimerWallbox',
            max(1, $this->ReadPropertyInteger('PollInterval')) * 1000
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug(
            'RequestAction',
            json_encode([
                'ident' => $Ident,
                'requestedValue' => $Value
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        if (strpos($Ident, 'Addr') !== 0) {
            throw new Exception('Ungültiger Ident: ' . $Ident);
        }

        $address = (int)substr($Ident, 4);
        $register = $this->GetRegisterByAddress($address);

        if ($register === null || empty($register['writable'])) {
            throw new Exception("Register {$address} ist nicht schreibbar.");
        }

        $scale = (float)($register['scale'] ?? 1.0);
        if ($scale == 0.0) {
            throw new Exception("Ungültige Skalierung für Register {$address}.");
        }

        $valueToWrite = (float)$Value;
        $correctionPercent = 0.0;

        if ($address === 10029) {
            $correctionPercent = $this->ReadPropertyFloat(
                'SollleistungKorrekturProzent'
            );
            $valueToWrite = $this->ApplySollleistungWriteCorrection(
                (float)$Value,
                $correctionPercent
            );
        }

        if ($address === 10029) {
            $maxPower = $this->GetWallboxMaxPower();
            if ($valueToWrite > $maxPower) {
                $this->SendDebug(
                    'RequestAction',
                    json_encode([
                        'address' => $address,
                        'name' => $register['name'],
                        'model' => $this->ReadPropertyString('WallboxModel'),
                        'requestedValue' => $Value,
                        'correctedValueToWrite' => $valueToWrite,
                        'maxPower' => $maxPower,
                        'status' => 'above_model_limit'
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
                throw new Exception(
                    "Sollleistung überschreitet die maximale Leistung des gewählten Wallbox-Modells ({$maxPower} W)."
                );
            }
        }

        $rawValue = (int)round($valueToWrite / $scale);

        $this->SendDebug(
            'RequestAction',
            json_encode([
                'address' => $address,
                'name' => $register['name'],
                'requestedValue' => $Value,
                'correctionPercent' => $correctionPercent,
                'correctedValueToWrite' => $valueToWrite,
                'scale' => $scale,
                'rawValue' => $rawValue,
                'rawMin' => $register['rawMin'] ?? null,
                'rawMax' => $register['rawMax'] ?? null
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        if (isset($register['rawMin']) && $rawValue < (int)$register['rawMin']) {
            throw new Exception("Wert für Register {$address} ist zu klein.");
        }

        if (isset($register['rawMax']) && $rawValue > (int)$register['rawMax']) {
            throw new Exception("Wert für Register {$address} ist zu gross.");
        }

        // Schreiben auslösen. Die unmittelbare Modbus-Antwort allein wird
        // nicht als endgültiges Erfolgskriterium verwendet, da die HCA G2
        // den Wert übernehmen kann, obwohl Symcon keine verwertbare
        // Write-Response zurückliefert.
        $writeAck = $this->WriteRegister($address, $rawValue);

        $this->SendDebug(
            'RequestAction',
            json_encode([
                'address' => $address,
                'name' => $register['name'],
                'requestedValue' => $Value,
                'correctedValueToWrite' => $valueToWrite,
                'correctionPercent' => $correctionPercent,
                'rawValue' => $rawValue,
                'immediateWriteAck' => $writeAck
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        // Den geschriebenen Rohwert durch Rücklesen bestätigen.
        // Mehrere Versuche sind absichtlich vorgesehen, da einige
        // Steuerwerte von der Wallbox leicht verzögert übernommen werden.
        $verified = $this->VerifyRegisterWrite(
            $address,
            $rawValue,
            4,
            250
        );

        if (!$verified) {
            $this->SendDebug(
                'RequestAction',
                json_encode([
                    'address' => $address,
                    'name' => $register['name'],
                    'status' => 'verification_failed',
                    'requestedValue' => $Value,
                    'correctedValueToWrite' => $valueToWrite,
                    'correctionPercent' => $correctionPercent,
                    'expectedRawValue' => $rawValue,
                    'immediateWriteAck' => $writeAck
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            throw new Exception(
                "Register {$address} wurde geschrieben, konnte aber nicht durch Rücklesen bestätigt werden."
            );
        }

        // Erst nach erfolgreicher Rücklese-Bestätigung die lokale Variable setzen.
        $this->SetValueIfChanged($Ident, $Value);

        $this->SendDebug(
            'RequestAction',
            json_encode([
                'address' => $address,
                'name' => $register['name'],
                'status' => 'verified',
                'requestedValue' => $Value,
                'correctedValueToWrite' => $valueToWrite,
                'correctionPercent' => $correctionPercent,
                'rawValue' => $rawValue,
                'immediateWriteAck' => $writeAck
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        // Anschließend alle ausgewählten Werte aktualisieren.
        $this->FetchWallboxData();
    }

    public function FetchWallboxData(): void
    {
        $lockName = 'GoodWeHCA2Poll_' . $this->InstanceID;

        if (!IPS_SemaphoreEnter($lockName, 1)) {
            $started = $this->ReadAttributeInteger('LastPollStarted');
            $age = $started > 0 ? time() - $started : 0;
            $this->SendDebug(
                'FetchWallboxData',
                "Abfrage läuft bereits seit {$age} s – Timeraufruf übersprungen.",
                0
            );
            return;
        }

        $startedAt = microtime(true);
        $this->WriteAttributeInteger('LastPollStarted', time());

        try {
            $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];

            if (
                $parentID === 0
                || !IPS_InstanceExists($parentID)
                || IPS_GetInstance($parentID)['InstanceStatus'] !== IS_ACTIVE
            ) {
                $this->SendDebug(
                    'FetchWallboxData',
                    'Keine aktive Modbus-Gateway-Parentinstanz verbunden.',
                    0
                );
                return;
            }

            $selected = json_decode($this->ReadPropertyString('SelectedRegisters'), true);
            $selectedMap = [];

            if (is_array($selected)) {
                foreach ($selected as $row) {
                    if (
                        is_array($row)
                        && !empty($row['selected'])
                        && isset($row['addr'])
                    ) {
                        $selectedMap[(string)$row['addr']] = true;
                    }
                }
            }

            $registers = [];
            foreach ($this->GetRegisters() as $register) {
                if (isset($selectedMap[(string)$register['address']])) {
                    $registers[] = $register;
                }
            }

            if ($registers === []) {
                $this->SendDebug(
                    'FetchWallboxData',
                    'Keine Wallbox-Register ausgewählt.',
                    0
                );
                return;
            }

            $selectedAddresses = array_map(
                fn($register) => (int)$register['address'],
                $registers
            );

            $blocks = $this->BuildReadBlocks($registers, 4, 100);

            $this->SendDebug(
                'FetchWallboxData',
                json_encode([
                    'selectedRegisterCount' => count($registers),
                    'selectedAddresses' => $selectedAddresses,
                    'blocks' => $blocks
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            $rawRegisters = [];
            $successfulBlocks = 0;
            $failedBlocks = [];

            foreach ($blocks as $block) {
                $blockStartedAt = microtime(true);

                $response = $this->ReadRegisterBlock(
                    $block['start'],
                    $block['count']
                );

                $blockDurationMs = (int)round(
                    (microtime(true) - $blockStartedAt) * 1000
                );

                if ($response === null) {
                    $failedBlocks[] = [
                        'start' => $block['start'],
                        'count' => $block['count'],
                        'durationMs' => $blockDurationMs
                    ];

                    $this->SendDebug(
                        'FetchWallboxData',
                        json_encode([
                            'block' => $block,
                            'status' => 'failed',
                            'durationMs' => $blockDurationMs
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0
                    );
                    continue;
                }

                $successfulBlocks++;
                $blockRaw = [];

                foreach ($response as $offset => $word) {
                    $address = $block['start'] + $offset;
                    $rawRegisters[$address] = $word;
                    $blockRaw[$address] = $word;
                }

                $this->SendDebug(
                    'FetchWallboxData',
                    json_encode([
                        'block' => $block,
                        'status' => 'ok',
                        'durationMs' => $blockDurationMs,
                        'raw' => $blockRaw
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
            }

            $decodedValues = [];
            $missingRegisters = [];

            foreach ($registers as $register) {
                $address = (int)$register['address'];
                $rawValue = $this->DecodeRegisterValue($register, $rawRegisters);

                if ($rawValue === null) {
                    $missingRegisters[] = $address;

                    $this->SendDebug(
                        'DecodeRegister',
                        json_encode([
                            'address' => $address,
                            'name' => $register['name'],
                            'type' => $register['type'],
                            'scale' => $register['scale'],
                            'error' => 'Rohwert nicht im gelesenen Registerblock vorhanden.'
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0
                    );
                    continue;
                }

                $scaledValue = $rawValue * (float)$register['scale'];
                $deviceScaledValue = $scaledValue;
                $correctionPercent = 0.0;

                if ($address === 10029) {
                    $correctionPercent = $this->ReadPropertyFloat(
                        'SollleistungKorrekturProzent'
                    );
                    $scaledValue = $this->ApplySollleistungReadCorrection(
                        (float)$deviceScaledValue,
                        $correctionPercent
                    );
                }

                $ident = 'Addr' . $address;
                $variableID = @$this->GetIDForIdent($ident);

                if ($variableID === false) {
                    $this->SendDebug(
                        'DecodeRegister',
                        json_encode([
                            'address' => $address,
                            'name' => $register['name'],
                            'raw' => $rawValue,
                            'type' => $register['type'],
                            'scale' => $register['scale'],
                            'deviceScaledValue' => $deviceScaledValue,
                            'correctionPercent' => $correctionPercent,
                            'scaled' => $scaledValue,
                            'error' => 'Zielvariable existiert nicht.'
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0
                    );
                    continue;
                }

                $variable = IPS_GetVariable($variableID);

                if ($variable['VariableType'] === VARIABLETYPE_FLOAT) {
                    $decimals = $this->GetScaleDecimals((float)$register['scale']);
                    $value = round((float)$scaledValue, $decimals);
                } else {
                    $value = (int)round($scaledValue);
                }

                $this->SetValueIfChanged($ident, $value);

                $decodedValues[$address] = [
                    'name' => $register['name'],
                    'raw' => $rawValue,
                    'type' => $register['type'],
                    'scale' => $register['scale'],
                    'deviceScaledValue' => $deviceScaledValue,
                    'correctionPercent' => $correctionPercent,
                    'value' => $value
                ];

                $this->SendDebug(
                    'DecodeRegister',
                    json_encode([
                        'address' => $address,
                        'name' => $register['name'],
                        'raw' => $rawValue,
                        'type' => $register['type'],
                        'scale' => $register['scale'],
                        'deviceScaledValue' => $deviceScaledValue,
                        'correctionPercent' => $correctionPercent,
                        'value' => $value
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
            }

            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

            $this->SendDebug(
                'FetchWallboxData',
                json_encode([
                    'summary' => true,
                    'selectedRegisters' => count($registers),
                    'blocks' => count($blocks),
                    'successfulBlocks' => $successfulBlocks,
                    'failedBlocks' => $failedBlocks,
                    'decodedRegisterCount' => count($decodedValues),
                    'missingRegisters' => $missingRegisters,
                    'durationMs' => $durationMs
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
        } catch (Throwable $e) {
            $this->SendDebug('FetchWallboxData', $e->getMessage(), 0);
            $this->LogMessage('GoodWeHCA2', $e->getMessage());
        } finally {
            $this->WriteAttributeInteger('LastPollStarted', 0);
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function BuildReadBlocks(
        array $registers,
        int $maxGap = 4,
        int $maxCount = 100
    ): array {
        $ranges = [];

        foreach ($registers as $register) {
            $start = (int)$register['address'];
            $length = in_array($register['type'], ['U32', 'S32'], true) ? 2 : 1;
            $ranges[] = [
                'start' => $start,
                'end'   => $start + $length - 1
            ];
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

        return array_map(
            fn($block) => [
                'start' => $block['start'],
                'count' => $block['end'] - $block['start'] + 1
            ],
            $blocks
        );
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
                    $this->SendDebug(
                        'ReadRegisterBlock',
                        json_encode([
                            'start' => $start,
                            'end' => $start + $count - 1,
                            'count' => $count,
                            'attempt' => $attempt,
                            'maxAttempts' => $maxAttempts,
                            'status' => 'ok',
                            'durationMs' => $durationMs,
                            'responseLength' => strlen($response),
                            'rawWords' => array_slice($words, 0, $count)
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        0
                    );

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

    private function VerifyRegisterWrite(
        int $address,
        int $expectedRawValue,
        int $attempts = 4,
        int $delayMs = 250
    ): bool {
        $attempts = max(1, $attempts);
        $delayMs = max(0, $delayMs);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($delayMs > 0) {
                IPS_Sleep($delayMs);
            }

            $startedAt = microtime(true);
            $response = $this->ReadRegisterBlock($address, 1);
            $durationMs = (int)round(
                (microtime(true) - $startedAt) * 1000
            );

            if ($response === null || !isset($response[0])) {
                $this->SendDebug(
                    'VerifyRegisterWrite',
                    json_encode([
                        'address' => $address,
                        'attempt' => $attempt,
                        'maxAttempts' => $attempts,
                        'status' => 'read_failed',
                        'expectedRawValue' => $expectedRawValue,
                        'durationMs' => $durationMs
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    0
                );
                continue;
            }

            $actualRawValue = (int)$response[0];
            $match = ($actualRawValue === $expectedRawValue);

            $this->SendDebug(
                'VerifyRegisterWrite',
                json_encode([
                    'address' => $address,
                    'attempt' => $attempt,
                    'maxAttempts' => $attempts,
                    'status' => $match ? 'verified' : 'value_mismatch',
                    'expectedRawValue' => $expectedRawValue,
                    'actualRawValue' => $actualRawValue,
                    'durationMs' => $durationMs
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );

            if ($match) {
                return true;
            }
        }

        $this->SendDebug(
            'VerifyRegisterWrite',
            json_encode([
                'address' => $address,
                'status' => 'failed',
                'expectedRawValue' => $expectedRawValue,
                'attempts' => $attempts,
                'delayMs' => $delayMs
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        return false;
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

        $this->SendDebug(
            'WriteRegister',
            json_encode([
                'address' => $address,
                'rawValue' => $value,
                'function' => 6,
                'dataHex' => $data['Data']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        $startedAt = microtime(true);
        $response = $this->SendDataToParent(json_encode($data));
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        if ($response === false) {
            $this->SendDebug(
                'WriteRegister',
                json_encode([
                    'address' => $address,
                    'status' => 'failed',
                    'rawValue' => $value,
                    'durationMs' => $durationMs
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
            return false;
        }

        $this->SendDebug(
            'WriteRegister',
            json_encode([
                'address' => $address,
                'status' => 'ok',
                'rawValue' => $value,
                'durationMs' => $durationMs,
                'responseLength' => strlen($response),
                'responseHex' => strtoupper(implode(' ', str_split(bin2hex($response), 2)))
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        return true;
    }

    public function GetConfigurationForm(): string
    {
        $selected = json_decode(
            $this->ReadPropertyString('SelectedRegisters'),
            true
        );

        if (!is_array($selected)) {
            $selected = [];
        }

        $selectedMap = [];
        foreach ($selected as $row) {
            if (!is_array($row)) {
                continue;
            }

            $address = $row['addr'] ?? $row['address'] ?? null;
            if ($address !== null) {
                $selectedMap[(string)$address] = (bool)($row['selected'] ?? false);
            }
        }

        $values = [];
        foreach ($this->GetRegisters() as $register) {
            $address = (string)$register['address'];

            $values[] = [
                'addr'            => $address,
                'selected'        => $selectedMap[$address] ?? true,
                'address_display' => $address,
                'name'            => $register['name']
            ];
        }

        return json_encode([
            'elements' => [
                [
                    'type'                        => 'List',
                    'name'                        => 'SelectedRegisters',
                    'caption'                     => 'Wallbox-Register auswählen',
                    'rowCount'                    => 18,
                    'add'                         => false,
                    'delete'                      => false,
                    'loadValuesFromConfiguration' => false,
                    'columns'                     => [
                        [
                            'caption' => '',
                            'name'    => 'addr',
                            'width'   => '0px',
                            'visible' => false,
                            'save'    => true
                        ],
                        [
                            'caption' => 'Aktiv',
                            'name'    => 'selected',
                            'width'   => '80px',
                            'save'    => true,
                            'edit'    => ['type' => 'CheckBox']
                        ],
                        [
                            'caption' => 'Adresse',
                            'name'    => 'address_display',
                            'width'   => '100px'
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto'
                        ]
                    ],
                    'values' => $values
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'WallboxModel',
                    'caption' => 'Wallbox-Modell',
                    'options' => [
                        ['caption' => 'GW7K-HCA-20 (7 kW)',  'value' => 'GW7K-HCA-20'],
                        ['caption' => 'GW11K-HCA-20 (11 kW)', 'value' => 'GW11K-HCA-20'],
                        ['caption' => 'GW22K-HCA-20 (22 kW)', 'value' => 'GW22K-HCA-20']
                    ]
                ],
                [
                    'type'    => 'IntervalBox',
                    'name'    => 'PollInterval',
                    'caption' => 'Abfrageintervall',
                    'suffix'  => 's'
                ],
                [
                    'type'    => 'HorizontalSlider',
                    'name'    => 'SollleistungKorrekturProzent',
                    'caption' => 'Korrektur Sollleistung',
                    'minimum' => -10,
                    'maximum' => 10,
                    'stepSize' => 1,
                    'displayValue' => true,
                    'suffix'  => ' %'
                ],
                [
                    'type'    => 'Label',
                    'caption' => '0 % = unverändert · Minus = weniger Leistung · Plus = mehr Leistung'
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Werte lesen',
                    'onClick' => 'GoodWeHCA2_FetchWallboxData($id);'
                ],
                [
                    'type' => 'Label',
                    'caption' => 'Sag danke und unterstütze den Modulentwickler:'
                ],
                [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Image',
                            'onClick' => "echo 'https://paypal.me/mbstern';",
                            'image' => 'data:image/jpeg;base64,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'
                        ],
                        [
                            'type' => 'Label',
                            'caption' => ''
                        ]
                    ]
                ]
            ]
        ]);
    }

    private function GetVariableDetails(string $unit): ?array
    {
        switch ($unit) {
            case 'V':
                return ['profile' => '~Volt', 'type' => VARIABLETYPE_FLOAT];

            case 'A':
                return ['profile' => '~Ampere', 'type' => VARIABLETYPE_FLOAT];

            case 'W':
                return ['profile' => 'GoodWeHCA2.Watt', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_setpower':
                return [
                    'profile' => $this->GetSetPowerProfileName(),
                    'type' => VARIABLETYPE_INTEGER
                ];

            case 'kWh':
                return ['profile' => '~Electricity', 'type' => VARIABLETYPE_FLOAT];

            case '%':
                return ['profile' => 'GoodWeHCA2.Percent', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_status':
                return ['profile' => 'GoodWeHCA2.Status', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_phase':
                return ['profile' => 'GoodWeHCA2.PhaseSwitch', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_charge_mode':
                return ['profile' => 'GoodWeHCA2.ChargeMode', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_onoff':
                return ['profile' => 'GoodWeHCA2.OnOff', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_connection':
                return ['profile' => 'GoodWeHCA2.Connection', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_power_spec':
                return ['profile' => 'GoodWeHCA2.PowerSpec', 'type' => VARIABLETYPE_INTEGER];

            case 'wb_type':
                return ['profile' => 'GoodWeHCA2.Type', 'type' => VARIABLETYPE_INTEGER];

        }

        return null;
    }

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('GoodWeHCA2.Watt')) {
            IPS_CreateVariableProfile('GoodWeHCA2.Watt', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('GoodWeHCA2.Watt', '', ' W');
            IPS_SetVariableProfileDigits('GoodWeHCA2.Watt', 0);
            IPS_SetVariableProfileValues('GoodWeHCA2.Watt', 0, 22000, 100);
        }

        $maxPower = $this->GetWallboxMaxPower();
        $setPowerProfile = $this->GetSetPowerProfileName();

        if (!IPS_VariableProfileExists($setPowerProfile)) {
            IPS_CreateVariableProfile($setPowerProfile, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText($setPowerProfile, '', ' W');
            IPS_SetVariableProfileDigits($setPowerProfile, 0);
        }

        // Maximalwert des Sollleistungsprofils bei jedem ApplyChanges
        // aktiv an das ausgewählte Wallbox-Modell anpassen.
        IPS_SetVariableProfileValues(
            $setPowerProfile,
            1400,
            $maxPower,
            100
        );

        $this->SendDebug(
            'CreateProfiles',
            json_encode([
                'setPowerProfile' => $setPowerProfile,
                'wallboxModel' => $this->ReadPropertyString('WallboxModel'),
                'minimum' => 1400,
                'maximum' => $maxPower,
                'step' => 100
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        if (!IPS_VariableProfileExists('GoodWeHCA2.Percent')) {
            IPS_CreateVariableProfile('GoodWeHCA2.Percent', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('GoodWeHCA2.Percent', '', ' %');
            IPS_SetVariableProfileDigits('GoodWeHCA2.Percent', 0);
            IPS_SetVariableProfileValues('GoodWeHCA2.Percent', 0, 100, 1);
        }

        $this->CreateAssociationProfile('GoodWeHCA2.Status', [
            0  => 'Frei / kein Stecker',
            1  => 'Frei / Fahrzeug verbunden',
            2  => 'Handshake mit Fahrzeug',
            3  => 'Lädt',
            4  => 'Laden beendet',
            5  => 'Störung',
            6  => 'Zeitplan startet',
            7  => 'Wartung',
            8  => 'Start fehlgeschlagen',
            9  => 'Systemupdate',
            10 => 'Unterbrochen: zu wenig PV/Batterie'
        ]);

        $this->CreateAssociationProfile(
            'GoodWeHCA2.PhaseSwitch',
            [0 => 'Aus', 1 => 'Ein']
        );

        $this->CreateAssociationProfile('GoodWeHCA2.ChargeMode', [
            0 => 'Sofortladen',
            1 => 'PV-Überschussladen',
            2 => 'PV + Batterie'
        ]);

        $this->CreateAssociationProfile(
            'GoodWeHCA2.OnOff',
            [1 => 'Laden aus', 2 => 'Laden ein']
        );

        $this->CreateAssociationProfile('GoodWeHCA2.Connection', [
            0 => 'Getrennt',
            1 => 'Teilweise verbunden',
            2 => 'Verbunden'
        ]);

        $this->CreateAssociationProfile(
            'GoodWeHCA2.PowerSpec',
            [0 => '7 kW', 1 => '11 kW', 2 => '22 kW']
        );

        $this->CreateAssociationProfile(
            'GoodWeHCA2.Type',
            [0 => 'Dreiphasig', 1 => 'Einphasig']
        );

    }

    private function CreateAssociationProfile(
        string $name,
        array $associations
    ): void {
        if (IPS_VariableProfileExists($name)) {
            return;
        }

        IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);

        foreach ($associations as $value => $caption) {
            IPS_SetVariableProfileAssociation(
                $name,
                (int)$value,
                (string)$caption,
                '',
                -1
            );
        }
    }

    private function SetValueIfChanged(string $ident, mixed $value): void
    {
        $variableID = @$this->GetIDForIdent($ident);

        if ($variableID === false) {
            return;
        }

        $variable = IPS_GetVariable($variableID);

        if ($variable['VariableType'] === VARIABLETYPE_FLOAT) {
            $newValue = (float)$value;
            $oldValue = (float)GetValue($variableID);
        } else {
            $newValue = (int)round((float)$value);
            $oldValue = (int)GetValue($variableID);
        }

        if ($newValue !== $oldValue) {
            $this->SetValue($ident, $newValue);
        }
    }

    private function GetSetPowerProfileName(): string
    {
        return 'GoodWeHCA2.SetPower.' . $this->InstanceID;
    }

    private function GetWallboxMaxPower(): int
    {
        switch ($this->ReadPropertyString('WallboxModel')) {
            case 'GW7K-HCA-20':
                return 7000;
            case 'GW22K-HCA-20':
                return 22000;
            case 'GW11K-HCA-20':
            default:
                return 11000;
        }
    }

    private function ApplySollleistungWriteCorrection(
        float $requestedValue,
        float $correctionPercent
    ): float {
        $factor = 1.0 + ($correctionPercent / 100.0);

        if ($factor <= 0.0) {
            $this->SendDebug(
                'SollleistungCorrection',
                json_encode([
                    'direction' => 'write',
                    'requestedValue' => $requestedValue,
                    'correctionPercent' => $correctionPercent,
                    'error' => 'Ungültiger Korrekturfaktor; Korrektur wird ignoriert.'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
            return $requestedValue;
        }

        $corrected = $requestedValue * $factor;

        $this->SendDebug(
            'SollleistungCorrection',
            json_encode([
                'direction' => 'write',
                'requestedValue' => $requestedValue,
                'correctionPercent' => $correctionPercent,
                'factor' => $factor,
                'correctedValueToDevice' => $corrected
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        return $corrected;
    }

    private function ApplySollleistungReadCorrection(
        float $deviceValue,
        float $correctionPercent
    ): float {
        $factor = 1.0 + ($correctionPercent / 100.0);

        if ($factor <= 0.0) {
            $this->SendDebug(
                'SollleistungCorrection',
                json_encode([
                    'direction' => 'read',
                    'deviceValue' => $deviceValue,
                    'correctionPercent' => $correctionPercent,
                    'error' => 'Ungültiger Korrekturfaktor; Korrektur wird ignoriert.'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                0
            );
            return $deviceValue;
        }

        $corrected = $deviceValue / $factor;

        $this->SendDebug(
            'SollleistungCorrection',
            json_encode([
                'direction' => 'read',
                'deviceValue' => $deviceValue,
                'correctionPercent' => $correctionPercent,
                'factor' => $factor,
                'correctedDisplayedValue' => $corrected
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            0
        );

        return $corrected;
    }

    private function GetScaleDecimals(float $scale): int
    {
        $scaleString = rtrim(
            rtrim(number_format($scale, 10, '.', ''), '0'),
            '.'
        );

        $dot = strpos($scaleString, '.');

        return $dot === false ? 0 : strlen($scaleString) - $dot - 1;
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

    private function GetRegisters(): array
    {
        // Aus dem ursprünglichen GoodWe-Modul ausgelagerte HCA-G2-Wallboxregister.
        // Die Registerdefinitionen, Typen, Skalierungen und Schreibgrenzen
        // wurden unverändert übernommen.
        $registers = [
            // Wallbox
["address" => 10009, "name" => "WB - Spannung L1",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 600],
            ["address" => 10010, "name" => "WB - Spannung L2",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 601],
            ["address" => 10011, "name" => "WB - Spannung L3",                    "type" => "U16", "unit" => "V",              "scale" => 0.1,   "pos" => 602],
            ["address" => 10012, "name" => "WB - Strom L1",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 603],
            ["address" => 10013, "name" => "WB - Strom L2",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 604],
            ["address" => 10014, "name" => "WB - Strom L3",                       "type" => "U16", "unit" => "A",              "scale" => 0.1,   "pos" => 605],
            ["address" => 10015, "name" => "WB - Ladeleistung",                   "type" => "U16", "unit" => "W",              "scale" => 100,   "pos" => 606],
            ["address" => 10016, "name" => "WB - Energie aktuelle Ladung",         "type" => "U16", "unit" => "kWh",            "scale" => 0.1,   "pos" => 607],
            ["address" => 10017, "name" => "WB - Status",                         "type" => "U16", "unit" => "wb_status",      "scale" => 1,     "pos" => 608],
            ["address" => 10023, "name" => "WB - Automatische Phasenumschaltung",  "type" => "U16", "unit" => "wb_phase",       "scale" => 1,     "pos" => 609,  "writable" => true, "rawMin" => 0,  "rawMax" => 1],
            ["address" => 10029, "name" => "WB - Sollleistung",                   "type" => "U16", "unit" => "wb_setpower",    "scale" => 100,   "pos" => 610, "writable" => true, "rawMin" => 14, "rawMax" => 220],
            ["address" => 10030, "name" => "WB - Batterie Entladegrenze",          "type" => "U16", "unit" => "%",              "scale" => 1,     "pos" => 611, "writable" => true, "rawMin" => 0,  "rawMax" => 100],
            ["address" => 10032, "name" => "WB - Lademodus",                      "type" => "U16", "unit" => "wb_charge_mode", "scale" => 1,     "pos" => 612, "writable" => true, "rawMin" => 0,  "rawMax" => 2],
            ["address" => 10058, "name" => "WB - Leistungsklasse",                 "type" => "U16", "unit" => "wb_power_spec",  "scale" => 1,     "pos" => 613],
            ["address" => 10059, "name" => "WB - Ausführung",                     "type" => "U16", "unit" => "wb_type",        "scale" => 1,     "pos" => 614],
            ["address" => 10060, "name" => "WB - Laden Ein/Aus",                   "type" => "U16", "unit" => "wb_onoff",       "scale" => 1,     "pos" => 615, "writable" => true, "rawMin" => 1,  "rawMax" => 2],
            ["address" => 10065, "name" => "WB - Energie gesamt",                  "type" => "U32", "unit" => "kWh",            "scale" => 0.1,   "pos" => 616],
            ["address" => 10075, "name" => "WB - Fahrzeugverbindung",              "type" => "U16", "unit" => "wb_connection",  "scale" => 1,     "pos" => 617],
        ];

        return $registers;
    }
}
