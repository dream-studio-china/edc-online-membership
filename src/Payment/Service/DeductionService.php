<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Payment\DTO\DeductionRequest;
use App\Payment\Entity\Deduction;
use App\Payment\Entity\Invoice;
use App\Payment\Repository\DeductionRepository;
use App\Wallet\Repository\WalletRepository;
use App\Wallet\Service\TransferServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class DeductionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeductionRepository $deductionRepository,
        private readonly WalletRepository $walletRepository,
        private readonly TransferServiceInterface $transferService,
        #[Autowire('%payment.system_wallet_id%')]
        private readonly ?int $systemWalletId = null,
    ) {}

    public function createRequestFromOptions(Invoice $invoice, array $options): ?DeductionRequest
    {
        if (isset($options['walletAmount'])) {
            $amount = (int) $options['walletAmount'];
            if ($amount <= 0) {
                return null;
            }

            return new DeductionRequest(
                Deduction::TYPE_WALLET_BALANCE,
                $amount,
                (string) ($options['currency'] ?? $invoice->getCurrency()),
                $options,
            );
        }

        $deduction = $options['deduction'] ?? null;
        if (!is_array($deduction)) {
            return null;
        }

        return new DeductionRequest(
            (string) ($deduction['type'] ?? Deduction::TYPE_WALLET_BALANCE),
            (int) ($deduction['amount'] ?? 0),
            (string) ($deduction['currency'] ?? $invoice->getCurrency()),
            array_merge($options, $deduction['options'] ?? []),
        );
    }

    public function applyFromOptions(Invoice $invoice, array $options): ?Deduction
    {
        $request = $this->createRequestFromOptions($invoice, $options);
        if ($request === null) {
            return null;
        }

        return $this->apply($invoice, $request->amount, $request->currency, $request->options, $request->type);
    }

    public function apply(Invoice $invoice, int $amount, string $currency, array $options = [], string $type = Deduction::TYPE_WALLET_BALANCE): Deduction
    {
        $this->validate($invoice, $amount, $currency, $type);

        $existing = $this->deductionRepository->findWalletBalanceByInvoice($invoice);
        if ($existing instanceof Deduction) {
            if ($existing->getStatus() === Deduction::STATUS_APPLIED) {
                return $existing;
            }
            throw new \RuntimeException(sprintf('Invoice deduction already exists with status "%s".', $existing->getStatus()));
        }

        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \RuntimeException('Invoice has no payer for wallet deduction.');
        }

        $systemWalletId = (int) ($options['systemWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet deduction.');
        }

        $wallet = $this->walletRepository->findByUserAndCurrency($payer->getId(), $currency);
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', strtoupper($currency)));
        }

        $referenceId = $options['deductionReferenceId'] ?? ('invoice-deduction-' . $invoice->getUuid());
        $deduction = new Deduction($invoice, $amount, $currency, $referenceId);
        $this->em->persist($deduction);

        try {
            $result = $this->transferService->transfer(
                $wallet->getId(),
                $systemWalletId,
                $amount,
                $referenceId,
                $invoice->getSubject() ?? ('Deduction for invoice ' . $invoice->getOutTradeNo()),
            );

            $deduction->markApplied($result->transaction->getUuid(), [
                'fromWalletId' => $wallet->getId(),
                'toWalletId' => $systemWalletId,
            ]);
            $this->em->flush();

            return $deduction;
        } catch (\Throwable $e) {
            $deduction->markFailed($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    public function release(Invoice $invoice, string $reason): ?Deduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoice($invoice);
        if (!$deduction instanceof Deduction) {
            return null;
        }

        return $this->reverse($deduction, 'invoice-deduction-release-' . $invoice->getUuid(), $reason, false);
    }

    public function refund(Invoice $invoice, string $reason): ?Deduction
    {
        $deduction = $this->deductionRepository->findAppliedByInvoice($invoice);
        if (!$deduction instanceof Deduction) {
            return null;
        }

        return $this->reverse($deduction, 'invoice-deduction-refund-' . $invoice->getUuid(), $reason, true);
    }

    public function sumAppliedAmount(Invoice $invoice): int
    {
        $sum = 0;
        foreach ($this->deductionRepository->findAppliedDeductions($invoice) as $deduction) {
            $sum += $deduction->getAmount();
        }

        return $sum;
    }

    public function findApplied(Invoice $invoice): ?Deduction
    {
        return $this->deductionRepository->findAppliedByInvoice($invoice);
    }

    public function hasApplied(Invoice $invoice): bool
    {
        return $this->findApplied($invoice) instanceof Deduction;
    }

    private function validate(Invoice $invoice, int $amount, string $currency, string $type): void
    {
        if ($type !== Deduction::TYPE_WALLET_BALANCE) {
            throw new \InvalidArgumentException(sprintf('Unsupported deduction type: %s', $type));
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deduction amount must be positive.');
        }
        if ($amount > $invoice->getAmount()) {
            throw new \InvalidArgumentException('Deduction amount cannot exceed invoice amount.');
        }
        if (strtoupper($currency) !== $invoice->getCurrency()) {
            throw new \InvalidArgumentException('Deduction currency must match invoice currency.');
        }
    }

    private function reverse(Deduction $deduction, string $referenceId, string $reason, bool $refund): Deduction
    {
        $invoice = $deduction->getInvoice();
        $payer = $invoice->getPayer();
        if ($payer === null || $payer->getId() === null) {
            throw new \RuntimeException('Invoice has no payer for deduction reversal.');
        }

        $metadata = $deduction->getMetadata() ?? [];
        $systemWalletId = (int) ($metadata['toWalletId'] ?? $this->systemWalletId ?? 0);
        if ($systemWalletId <= 0) {
            throw new \InvalidArgumentException('systemWalletId is required for wallet deduction reversal.');
        }

        $wallet = $this->walletRepository->findByUserAndCurrency($payer->getId(), $deduction->getCurrency());
        if ($wallet === null || $wallet->getId() === null) {
            throw new \RuntimeException(sprintf('No %s wallet found for payer.', $deduction->getCurrency()));
        }

        $result = $this->transferService->transfer(
            $systemWalletId,
            $wallet->getId(),
            $deduction->getAmount(),
            $referenceId,
            $reason,
        );

        if ($refund) {
            $deduction->markRefunded($result->transaction->getUuid(), $reason);
        } else {
            $deduction->markReleased($result->transaction->getUuid(), $reason);
        }
        $this->em->flush();

        return $deduction;
    }
}
