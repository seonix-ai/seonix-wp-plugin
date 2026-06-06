<?php
namespace Seonix\Tests\Fakes;

use Seonix_Fix_Method;

/**
 * Minimal Seonix_Fix_Method implementation used by tests that exercise
 * components which depend on the interface (registry, history, controller)
 * without exercising any real fix logic.
 */
final class FakeFixMethod implements Seonix_Fix_Method {

    private string $key;
    private $dryRunResult;
    private $applyResult;
    private $rollbackResult;
    private $validateResult;

    public function __construct(
        string $key,
        $dryRunResult = null,
        $applyResult = null,
        $rollbackResult = null,
        $validateResult = true
    ) {
        $this->key            = $key;
        $this->dryRunResult   = $dryRunResult ?? array( 'before' => 'old', 'after' => 'new', 'diff' => 'old→new', 'target' => array() );
        $this->applyResult    = $applyResult  ?? array( 'history_id' => 1, 'before' => 'old', 'after' => 'new' );
        $this->rollbackResult = $rollbackResult ?? array( 'before' => 'new', 'after' => 'old' );
        $this->validateResult = $validateResult;
    }

    public function key(): string {
        return $this->key;
    }

    public function validate_params( array $params ) {
        return $this->validateResult;
    }

    public function dry_run( array $params ) {
        return $this->dryRunResult;
    }

    public function apply( array $params ) {
        return $this->applyResult;
    }

    public function rollback( int $history_id ) {
        return $this->rollbackResult;
    }
}
