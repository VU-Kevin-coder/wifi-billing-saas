## [t3] Defined Phase 1 preservation invariants
- Codebase/domain discoveries: Existing public API includes site-agent command polling/completion and payment status/activation plus four provider webhook routes; package access is tenant-scoped and agent-backed production sessions remain provisioning until agent confirmation.
- Wrong assumptions and corrections: The user-selected Docker Compose and CHR lab targets do not authorize any current infrastructure or router changes; the resolved clarification explicitly keeps them for later lab work.
- Debugging dead-ends and what actually worked: Several large test files exceeded one-shot view limits; focused range reads plus test-name searches were sufficient to map behavior.
- Techniques/patterns worth reusing: Cite existing test names as characterization evidence without claiming they ran; distinguish payment verification, fulfillment completion, and router-confirmed activation as separate observable states.
- Learnings consumed: []
