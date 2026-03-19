<!-- ========= FULL-WIDTH Monthly Target CARD ========= -->
<div class="row">
  <div class="col-12">
    <div class="card bg-welcome-img overflow-hidden">
      <div class="card-body">
        <div>
          <h3 class="text-white fw-semibold fs-20 lh-base">Monthly Target — Converted Leads</h3>
          <p class="mb-2 text-white-50">Track how close you are to this month’s goal.</p>

          <div class="bg-white p-3 rounded">
            <div class="d-flex align-items-center mb-2">
              <div class="flex-grow-1 me-2">
                <div class="progress" style="height:12px;">
                  <div id="tc-progress" class="progress-bar" role="progressbar" style="width:0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
              </div>
              <div style="min-width:64px;">
                <span id="tc-percent" class="fw-semibold">0%</span>
              </div>
            </div>

            <div class="row text-center">
              <div class="col-4">
                <p class="text-muted text-uppercase mb-0 fs-12">Converted</p>
                <h5 id="tc-converted" class="fw-medium mb-0">0</h5>
              </div>
              <div class="col-4">
                <p class="text-muted text-uppercase mb-0 fs-12">Remaining</p>
                <h5 id="tc-remaining" class="fw-medium mb-0">0</h5>
              </div>
              <div class="col-4">
                <p class="text-muted text-uppercase mb-0 fs-12">Target</p>
                <h5 id="tc-target" class="fw-medium mb-0">0</h5>
              </div>
            </div>

            <hr class="hr-dashed">

            <div class="d-flex align-items-center">
              <div class="flex-shrink-0 me-2">
                <div id="tc-badge" class="d-flex justify-content-center align-items-center thumb-md border-dashed rounded-circle">
                  <i class="iconoir-rocket fs-5"></i>
                </div>
              </div>
              <div class="flex-grow-1">
                <h6 id="tc-title" class="mb-1 fw-semibold">Let’s hit the goal!</h6>
                <p id="tc-text" class="mb-0 text-muted">You’re on your way—keep pushing.</p>
              </div>
              <span class="badge bg-primary-subtle text-primary" id="tc-target-badge">Target: 0</span>
            </div>
          </div>
        </div>
      </div><!--end card-body-->
    </div><!--end card-->
  </div><!--end col-->
</div><!--end row-->

<!-- ========= BELOW: Pace/Tips (left) + KPIs (right) ========= -->
<div class="row justify-content-center">
  <!-- LEFT -->
  <div class="col-lg-7">
    <div class="row">
      <div class="col-12">
        <div class="card bg-globe-img">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
              <span class="fs-16 fw-semibold">Pace to Target</span>
              <span class="badge bg-info-subtle text-info" id="tc-days-left">0 day(s) left</span>
            </div>
            <h4 class="my-2 fs-24 fw-semibold"><span id="tc-perday">0</span> <small class="font-14">/ day needed</small></h4>
            <p id="tc-tip" class="mb-3 text-muted fw-semibold">Batch callbacks in morning & evening peak hours.</p>
            <button type="button" class="btn btn-soft-primary">View Callbacks</button>
            <button type="button" class="btn btn-soft-danger">Schedule Follow-ups</button>
          </div>
        </div>
      </div><!--end col-->
    </div><!--end row-->
  </div><!--end col-->

  <!-- RIGHT: KPI snapshot -->
  <div class="col-lg-5">
    <div class="row justify-content-center">
      <div class="col-md-6 col-lg-6">
        <div class="card bg-corner-img">
          <div class="card-body">
            <div class="row d-flex justify-content-center">
              <div class="col-9">
                <p class="text-muted text-uppercase mb-0 fw-normal fs-13">Converted</p>
                <h4 id="kpi-conv" class="mt-1 mb-0 fw-medium">0</h4>
              </div>
              <div class="col-3 align-self-center">
                <div class="d-flex justify-content-center align-items-center thumb-md border-dashed border-success rounded mx-auto">
                  <i class="iconoir-check-circle fs-22 text-success"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div><!--end col-->

      <div class="col-md-6 col-lg-6">
        <div class="card bg-corner-img">
          <div class="card-body">
            <div class="row d-flex justify-content-center">
              <div class="col-9">
                <p class="text-muted text-uppercase mb-0 fw-normal fs-13">Qualified</p>
                <h4 id="kpi-qual" class="mt-1 mb-0 fw-medium">0</h4>
              </div>
              <div class="col-3 align-self-center">
                <div class="d-flex justify-content-center align-items-center thumb-md border-dashed border-info rounded mx-auto">
                  <i class="iconoir-badge-check fs-22 text-info"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div><!--end col-->

      <div class="col-md-6 col-lg-6">
        <div class="card bg-corner-img">
          <div class="card-body">
            <div class="row d-flex justify-content-center">
              <div class="col-9">
                <p class="text-muted text-uppercase mb-0 fw-normal fs-13">Attended</p>
                <h4 id="kpi-att" class="mt-1 mb-0 fw-medium">0</h4>
              </div>
              <div class="col-3 align-self-center">
                <div class="d-flex justify-content-center align-items-center thumb-md border-dashed border-primary rounded mx-auto">
                  <i class="iconoir-task-list fs-22 text-primary"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div><!--end col-->

      <div class="col-md-6 col-lg-6">
        <div class="card bg-corner-img">
          <div class="card-body">
            <div class="row d-flex justify-content-center">
              <div class="col-9">
                <p class="text-muted text-uppercase mb-0 fw-normal fs-13">Canceled</p>
                <h4 id="kpi-cancel" class="mt-1 mb-0 fw-medium">0</h4>
              </div>
              <div class="col-3 align-self-center">
                <div class="d-flex justify-content-center align-items-center thumb-md border-dashed border-danger rounded mx-auto">
                  <i class="iconoir-cancel fs-22 text-danger"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div><!--end col-->
    </div><!--end row-->
  </div><!--end col-->
</div><!--end row-->

<!-- Telecaller Target Logic (unchanged) -->
<script>
  const KPIS = { attended: 120, qualified: 48, converted: 18, canceled: 12 };
  const MONTHLY_TARGET = 30;

  function daysLeftInMonth(d = new Date()) {
    const y = d.getFullYear(), m = d.getMonth();
    const last = new Date(y, m + 1, 0).getDate();
    return Math.max(0, last - d.getDate());
  }

  function fillKPIs() {
    document.getElementById('kpi-att').textContent    = KPIS.attended;
    document.getElementById('kpi-qual').textContent   = KPIS.qualified;
    document.getElementById('kpi-conv').textContent   = KPIS.converted;
    document.getElementById('kpi-cancel').textContent = KPIS.canceled;
  }

  function renderTarget() {
    const conv = KPIS.converted;
    const tgt  = MONTHLY_TARGET;
    const remaining = Math.max(0, tgt - conv);
    const pct = Math.min(100, Math.round((conv / tgt) * 100 || 0));
    const left = daysLeftInMonth();
    const perDay = left > 0 ? Math.ceil(remaining / left) : remaining;

    document.getElementById('tc-converted').textContent   = conv;
    document.getElementById('tc-remaining').textContent   = remaining;
    document.getElementById('tc-target').textContent      = tgt;
    document.getElementById('tc-target-badge').textContent= `Target: ${tgt}`;
    document.getElementById('tc-percent').textContent     = pct + '%';
    document.getElementById('tc-days-left').textContent   = `${left} day(s) left`;
    document.getElementById('tc-perday').textContent      = perDay;

    const bar = document.getElementById('tc-progress');
    bar.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', pct);
    bar.classList.remove('bg-danger','bg-warning','bg-success');
    if (pct < 40) bar.classList.add('bg-danger');
    else if (pct < 75) bar.classList.add('bg-warning');
    else bar.classList.add('bg-success');

    const badge = document.getElementById('tc-badge');
    const title = document.getElementById('tc-title');
    const text  = document.getElementById('tc-text');
    badge.classList.remove('border-danger','border-warning','border-success');

    if (pct < 40) {
      badge.classList.add('border-danger');
      title.textContent = '🚨 Urgent: Big push needed!';
      text.textContent  = `Only ${pct}% hit. Prioritize hot leads, schedule callbacks tightly, and aim for quick closes today.`;
    } else if (pct < 75) {
      badge.classList.add('border-warning');
      title.textContent = '⚡ Keep the momentum!';
      text.textContent  = `${pct}% reached. Double down on qualified leads and follow-up within 24 hours.`;
    } else if (pct < 100) {
      badge.classList.add('border-success');
      title.textContent = '🎯 Almost there!';
      text.textContent  = `${pct}% reached. Focus on high-intent prospects to finish strong.`;
    } else {
      badge.classList.add('border-success');
      title.textContent = '🏆 Target achieved!';
      text.textContent  = `Great job: ${conv}/${tgt}. Set a stretch goal and keep closing!`;
    }

    document.getElementById('tc-tip').textContent =
      left > 0 ? `Need ~${perDay}/day for ${left} day(s). Focus callbacks at 10–12am & 5–7pm for higher pick-up rates.`
               : `Month ends today — prioritize hottest leads and final callbacks now.`;
  }

  fillKPIs();
  renderTarget();
</script>
