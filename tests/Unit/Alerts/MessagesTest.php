<?php
declare(strict_types=1);

namespace PlaidMonitor\Tests\Unit\Alerts;

use PHPUnit\Framework\TestCase;
use PlaidMonitor\Alerts\Messages;
use PlaidMonitor\Rules\FlaggedTransaction;
use PlaidMonitor\Rules\Severity;
use PlaidMonitor\Rules\TriggeredRule;
use PlaidMonitor\Tests\Support\Fixtures;

class MessagesTest extends TestCase
{
    public function testSlackMessagesCannotMentionTheChannel(): void
    {
        $flagged = new FlaggedTransaction(Fixtures::transaction(['merchant_name' => '<!channel> Free Money']));
        $flagged->add(new TriggeredRule('r', 'new_merchant', 'New merchant', 'New merchant: <!channel>', Severity::Medium, [], false, true));

        $text = Messages::fraudSlack([$flagged], 'Bank <Checking>', 'alert_1');

        $this->assertStringNotContainsString('<!channel>', $text);
        $this->assertStringContainsString('&lt;!channel&gt; Free Money', $text);
    }

    public function testEmailListsOnlyTheRulesThatOptedIn(): void
    {
        $flagged = new FlaggedTransaction(Fixtures::transaction(['amount' => 1500.0]));
        $flagged->add(new TriggeredRule('a', 'amount_threshold', 'Large purchase', 'over 1000', Severity::High, [], true, false));
        $flagged->add(new TriggeredRule('b', 'new_merchant', 'New merchant', 'never seen', Severity::Medium, [], false, true));

        $body = Messages::fraudEmailBody([$flagged], 'Bank Checking', 'alert_1', static fn(TriggeredRule $r) => $r->emailAlert);

        $this->assertStringContainsString('Large purchase', $body);
        $this->assertStringNotContainsString('New merchant', $body);
        $this->assertStringContainsString('USD 1,500.00', $body);
    }
}
