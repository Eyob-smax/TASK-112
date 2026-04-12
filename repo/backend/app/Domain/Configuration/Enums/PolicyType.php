<?php

namespace App\Domain\Configuration\Enums;

enum PolicyType: string
{
    case Coupon         = 'coupon';
    case Promotion      = 'promotion';
    case PurchaseLimit  = 'purchase_limit';
    case Blacklist      = 'blacklist';
    case Whitelist      = 'whitelist';
    case Campaign       = 'campaign';
    case LandingTopic   = 'landing_topic';
    case AdSlot         = 'ad_slot';
    case HomepageModule = 'homepage_module';

    public function label(): string
    {
        return match ($this) {
            self::Coupon         => 'Coupon Rule',
            self::Promotion      => 'Promotion Rule',
            self::PurchaseLimit  => 'Purchase Limit',
            self::Blacklist      => 'Blacklist',
            self::Whitelist      => 'Whitelist',
            self::Campaign       => 'Campaign',
            self::LandingTopic   => 'Landing Topic',
            self::AdSlot         => 'Ad Slot',
            self::HomepageModule => 'Homepage Module',
        };
    }

    /**
     * Whether this policy type affects purchasing behavior and requires canary validation.
     */
    public function affectsPurchasing(): bool
    {
        return match ($this) {
            self::Coupon, self::Promotion, self::PurchaseLimit,
            self::Blacklist, self::Whitelist => true,
            default                          => false,
        };
    }
}
