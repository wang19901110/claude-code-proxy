<?php

declare(strict_types=1);

namespace FreeGateway;

enum FailureKind: string
{
    case CLIENT_INVALID = 'client_invalid';
    case UNSUPPORTED_CAPABILITY = 'unsupported_capability';
    case CONTEXT_EXCEEDED = 'context_exceeded';
    case AUTH = 'auth';
    case FORBIDDEN = 'forbidden';
    case RATE_LIMIT = 'rate_limit';
    case MODEL_UNAVAILABLE = 'model_unavailable';
    case UPSTREAM_TRANSIENT = 'upstream_transient';
    case PROTOCOL_INVALID = 'protocol_invalid';
    case EMPTY_RESPONSE = 'empty_response';
    case POST_COMMIT_FAILURE = 'post_commit_failure';
}
