<?php

declare(strict_types=1);

namespace App\Integrations\Kyc;

/** Deterministic checks for local development and tests (`KYC_DRIVER=fake`). */
final class FakeKycProvider implements KycProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function runChecks(KycCheckRequest $request): KycCheckResult
    {
        $name = trim($request->firstName.' '.$request->lastName);

        return new KycCheckResult(
            checks: [
                ['label' => 'Document authenticity', 'result' => 'MRZ valid', 'passed' => true],
                ['label' => 'Face match', 'result' => '96.1%', 'passed' => true],
                ['label' => 'Name match', 'result' => 'Match', 'passed' => true],
                ['label' => 'Sanctions & PEP screening', 'result' => 'No hits', 'passed' => true],
            ],
            extracted: [
                ['field' => 'Full name', 'document' => mb_strtoupper($name), 'profile' => $name, 'match' => true],
                ['field' => 'Date of birth', 'document' => $request->dateOfBirth, 'profile' => $request->dateOfBirth, 'match' => true],
            ],
            providerReference: 'fake_'.$request->reference,
        );
    }
}
