$baseDir = "src\modules\hr\pages"

$checks = @(
  @{ file="ManpowerRequests.jsx"; terms=@("rejectModal","rejection_reason","openRejectModal","handleStatus") },
  @{ file="Interviews.jsx";       terms=@("showFeedback","feedbackForm","technical_score","handleFeedback","Star") },
  @{ file="Onboarding.jsx";       terms=@("document_checklist","DOC_ITEMS","handleDocToggle","DOC_LABELS") },
  @{ file="Employees.jsx";        terms=@("dob","gender","address","probation_end_date","confirmation_date") },
  @{ file="Candidates.jsx";       terms=@("ai_score","Kanban","hrApi","stage") },
  @{ file="CandidateProfile.jsx"; terms=@("ai_score","final_decision","hrApi") },
  @{ file="JobPostings.jsx";      terms=@("hrApi","closing_date","sources") },
  @{ file="OfferLetters.jsx";     terms=@("hrApi","offered_ctc","rejection_reason") },
  @{ file="HRDashboard.jsx";      terms=@("hrApi","pipeline") }
)

$allOk = $true
foreach ($check in $checks) {
  $path = Join-Path $baseDir $check.file
  if (-not (Test-Path $path)) {
    Write-Host "FILE MISSING: $($check.file)" -ForegroundColor Red
    $allOk = $false
    continue
  }
  $content = Get-Content $path -Raw
  $missing = @()
  foreach ($term in $check.terms) {
    if ($content -notmatch [regex]::Escape($term)) {
      $missing += $term
    }
  }
  if ($missing.Count -gt 0) {
    Write-Host "GAPS in $($check.file): $($missing -join ', ')" -ForegroundColor Yellow
    $allOk = $false
  } else {
    Write-Host "PASS: $($check.file)" -ForegroundColor Green
  }
}

if ($allOk) { Write-Host "`nAll checks passed!" -ForegroundColor Cyan }
else { Write-Host "`nSome gaps found - need fixing." -ForegroundColor Red }
