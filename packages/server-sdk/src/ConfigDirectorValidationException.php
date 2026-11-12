<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Thrown when an argument has a value the SDK cannot use, such as a missing server SDK key or a
 * `null` in-code default value.
 */
final class ConfigDirectorValidationException extends ConfigDirectorException {}
