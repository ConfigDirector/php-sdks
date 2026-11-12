<?php

declare(strict_types=1);

namespace ConfigDirector;

/**
 * Why an evaluation returned the value it did. The text of each reason is the same in every
 * ConfigDirector SDK.
 */
enum EvaluationReason: string
{
    /** A value from ConfigDirector was returned. */
    case FoundMatch = 'found-match';

    /**
     * The default targeting rule value was returned: a percentage rollout was skipped because the
     * context has no `id` to place it in a share, and no rule after the rollout applied.
     */
    case RolloutSkippedNoIdentifier = 'rollout-skipped-no-identifier';

    /** The in-code default value was returned because no config has the given key. */
    case ConfigStateMissing = 'config-state-missing';

    /** The in-code default value was returned because the client has no values to serve yet. */
    case ClientNotReady = 'client-not-ready';

    /**
     * The in-code default value was returned because it is a string and the config is a boolean,
     * an integer or a float.
     */
    case TypeMismatch = 'type-mismatch';

    /** The in-code default value was returned because the config has no value. */
    case ValueMissing = 'value-missing';

    /**
     * The in-code default value was returned because it is an `int` or a `float` and the config's
     * value is not a number of that type.
     */
    case InvalidNumber = 'invalid-number';

    /**
     * The in-code default value was returned because it is an array and the config's value is not
     * a JSON object or a JSON array.
     */
    case InvalidJson = 'invalid-json';

    /**
     * The in-code default value was returned because it is a `bool` and the config's value is
     * neither `true` nor `false`.
     */
    case InvalidBoolean = 'invalid-boolean';
}
