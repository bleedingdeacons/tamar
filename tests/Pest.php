<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test file in this suite ran on wp-mocks' TestCase as a PHPUnit class,
// so the whole Unit directory is bound to it here. None of them is
// WordPress-coupled in any deep way — the parser, builder and service are
// exercised against HTML fixtures and an in-memory transport — but that
// TestCase is what brings Brain Monkey's lifecycle and Mockery's integration,
// so anything added later that reaches a WordPress function finds them in
// place.
//
// If a pure-PHP test ever wants plain PHPUnit instead, it has to be carved out
// of this binding by naming the files that remain on it, the way Trusted's and
// Scrutiny's tests/Pest.php do.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in('Unit');
