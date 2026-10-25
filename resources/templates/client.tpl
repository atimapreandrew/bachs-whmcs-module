{if isset($bachsError)}
  <div class="alert alert-warning" role="alert">{$bachsError|escape}</div>
{else}
  <link rel="stylesheet" href="{$bachsRoot|escape}/assets/bachs.css">
  <section class="bachs-payment bachs-client-settings">
    <div class="bachs-payment-top"><span class="bachs-wordmark">bachs<span aria-hidden="true">↗</span></span>{if $bachsSandbox}<span class="bachs-badge">Sandbox</span>{/if}</div>
    <h2>Payment settings</h2>
    <p>Manage your saved cards securely at Bachs. Turn off local automatic payments here whenever you need to.</p>
    {foreach $bachsMethods as $method}
      <article class="bachs-method">
        <div><strong>{$method.brand|escape} ···· {$method.last4|escape}</strong><p>Expires {$method.expiry_month|escape}/{$method.expiry_year|escape} · {$method.state|escape}</p></div>
        {if $method.state eq 'active'}
          <form method="post" action="{$bachsRoot|escape}/manage.php">
            <input type="hidden" name="token" value="{$bachsToken|escape}">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="method_id" value="{$method.id|escape}">
            <button type="submit" class="bachs-secondary">Turn off automatic payments</button>
          </form>
        {/if}
      </article>
    {foreachelse}
      <div class="bachs-empty"><strong>No saved cards yet</strong><p>When paying an eligible invoice, choose to save your card and authorize renewal collection.</p></div>
    {/foreach}
    {if $bachsCanSetup}
    <form method="post" action="{$bachsRoot|escape}/manage.php">
      <input type="hidden" name="token" value="{$bachsToken|escape}">
      <input type="hidden" name="action" value="setup">
      <label class="bachs-consent"><input type="checkbox" name="consent" value="1" required><span>{$bachsConsent|escape}</span></label>
      <p class="bachs-muted">USD card setup does not charge you. NGN cards can be saved while paying an invoice.</p>
      <button type="submit" class="bachs-button">Add a USD card securely <span aria-hidden="true">↗</span></button>
    </form>
    {/if}
    <form method="post" action="{$bachsRoot|escape}/manage.php">
      <input type="hidden" name="token" value="{$bachsToken|escape}">
      <input type="hidden" name="action" value="portal">
      <button type="submit" class="bachs-button">Manage cards at Bachs <span aria-hidden="true">↗</span></button>
    </form>
    <p class="bachs-muted">Turning off automatic payments prevents local collection. Card removal at Bachs is managed in the secure portal.</p>
  </section>
{/if}
