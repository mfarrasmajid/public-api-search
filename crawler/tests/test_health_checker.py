from crawler.pipelines.health_checker import classify


def test_fast_2xx_is_healthy():
    assert classify(200, 120) == "healthy"


def test_auth_required_still_counts_as_alive():
    assert classify(401, 100) == "healthy"
    assert classify(403, 100) == "healthy"


def test_server_errors_are_unhealthy():
    assert classify(500, 100) == "unhealthy"
    assert classify(503, 100) == "unhealthy"


def test_slow_response_is_degraded():
    assert classify(200, 5000) == "degraded"


def test_not_found_is_degraded_not_dead():
    assert classify(404, 100) == "degraded"
