import React, { useState } from 'react';
import { ExternalLink, Copy, CheckCircle, AlertCircle } from 'lucide-react';
import { hrApi } from '../../services/hrApi';
import { useToast } from '@/hooks/useToast'

export default function ExternalPostingCard({ job, onUpdate }) {
  const toast = useToast()
  const [externalIds, setExternalIds] = useState(job.external_job_ids || {});
  const [showIdInput, setShowIdInput] = useState(null);
  const [saving, setSaving] = useState(false);
  const [toastMsg, setToastMsg] = useState(null);

  const showToast = (msg, type = 'success') => {
    setToastMsg({ msg, type });
    setTimeout(() => setToastMsg(null), 3000);
  };

  const platforms = [
    {
      id: 'trulytalents',
      name: 'TrulyTalents.com',
      url: 'https://trulytalents.com/',
      color: 'blue',
      icon: '🚀',
    },
    {
      id: 'linkedin',
      name: 'LinkedIn',
      url: 'https://www.linkedin.com/jobs/post/',
      color: 'blue',
      icon: '💼',
    },
    {
      id: 'naukri',
      name: 'Naukri.com',
      url: 'https://www.naukri.com/mnjuser/homepage',
      color: 'purple',
      icon: '📋',
    },
    {
      id: 'indeed',
      name: 'Indeed',
      url: 'https://employers.indeed.com/p/post-job',
      color: 'green',
      icon: '🔍',
    },
    {
      id: 'monster',
      name: 'Monster',
      url: 'https://www.monster.com/',
      color: 'orange',
      icon: '👹',
    },
  ];

  const generateJobText = () => {
    return `
═══════════════════════════════════════
📢 JOB POSTING
═══════════════════════════════════════

📌 POSITION: ${job.title}
🏢 DEPARTMENT: ${job.department}
📍 LOCATION: ${job.location}
💼 JOB TYPE: ${job.job_type}
${job.salary_from ? `💰 SALARY: ₹${job.salary_from} - ₹${job.salary_to}` : ''}
👥 OPENINGS: ${job.number_of_openings}
${job.closing_date ? `📅 APPLY BY: ${new Date(job.closing_date).toLocaleDateString()}` : ''}

───────────────────────────────────────
📝 DESCRIPTION:
───────────────────────────────────────
${job.description || 'No description provided'}

───────────────────────────────────────
✅ REQUIREMENTS:
───────────────────────────────────────
${job.requirements || 'No requirements specified'}

═══════════════════════════════════════
    `.trim();
  };

  const handlePostExternal = (platform) => {
    // Copy job details to clipboard
    const jobText = generateJobText();
    
    navigator.clipboard.writeText(jobText).then(() => {
      showToast(`Job details copied! Opening ${platform.name}...`);
      
      // Open platform in new tab
      window.open(platform.url, '_blank');
      
      // Show input to save external ID
      setShowIdInput(platform.id);
    }).catch(() => {
      showToast('Failed to copy job details', 'error');
    });
  };

  const saveExternalId = async (platformId, externalId) => {
    if (!externalId.trim()) {
      toast.error('Please enter a valid job ID');
      return;
    }

    setSaving(true);
    try {
      const res = await hrApi.jobs.updateExternalId(job.id, platformId, externalId.trim());
      
      setExternalIds(res.external_ids);
      setShowIdInput(null);
      toast.success(`${platformId} job ID saved!`);
      
      // Notify parent to refresh job data
      if (onUpdate) onUpdate();
      
    } catch (err) {
      console.error('Error saving external ID:', err);
      toast.error(err.response?.data?.message || 'Failed to save job ID');
    } finally {
      setSaving(false);
    }
  };

  const copyId = (platformId) => {
    navigator.clipboard.writeText(externalIds[platformId]);
    toast.success('Job ID copied!');
  };

  return (
    <div className="bg-white rounded-lg shadow p-6">
      <h2 className="text-xl font-bold mb-4 flex items-center gap-2">
        📤 Post to Job Boards
      </h2>
      
      <div className="space-y-3">
        {platforms.map((platform) => {
          const isPosted = externalIds[platform.id];
          
          return (
            <div
              key={platform.id}
              className="border rounded-lg p-4 hover:border-gray-300 transition"
            >
              <div className="flex items-center justify-between mb-2">
                <h3 className="font-semibold flex items-center gap-2">
                  <span>{platform.icon}</span>
                  {platform.name}
                </h3>
                {isPosted && (
                  <span className="text-green-600 flex items-center gap-1 text-sm">
                    <CheckCircle size={16} />
                    Posted
                  </span>
                )}
              </div>
              
              {!isPosted ? (
                <div>
                  <button
                    onClick={() => handlePostExternal(platform)}
                    className={`px-4 py-2 bg-${platform.color}-600 text-white rounded hover:bg-${platform.color}-700 flex items-center gap-2 transition`}
                    style={{
                      backgroundColor: platform.color === 'blue' ? '#2563eb' :
                                      platform.color === 'purple' ? '#9333ea' :
                                      platform.color === 'green' ? '#16a34a' :
                                      platform.color === 'orange' ? '#ea580c' : '#2563eb'
                    }}
                  >
                    <ExternalLink size={16} />
                    Post to {platform.name}
                  </button>
                  
                  <p className="text-sm text-gray-500 mt-2 flex items-start gap-1">
                    <AlertCircle size={14} className="mt-0.5 flex-shrink-0" />
                    <span>
                      Job details will be copied to clipboard. Paste them when posting.
                    </span>
                  </p>
                  
                  {showIdInput === platform.id && (
                    <div className="mt-3 animate-fadeIn">
                      <label className="text-sm font-medium block mb-1">
                        📝 Paste Job ID from {platform.name}:
                      </label>
                      <div className="flex gap-2">
                        <input
                          type="text"
                          placeholder={`e.g., ${platform.id.toUpperCase()}-12345`}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                              saveExternalId(platform.id, e.target.value);
                            }
                          }}
                          className="flex-1 px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                          autoFocus
                          disabled={saving}
                        />
                        <button
                          onClick={(e) => {
                            const input = e.target.previousElementSibling;
                            saveExternalId(platform.id, input.value);
                          }}
                          disabled={saving}
                          className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                        >
                          {saving ? 'Saving...' : 'Save'}
                        </button>
                      </div>
                      <p className="text-xs text-gray-500 mt-1">
                        Press Enter or click Save to store the job ID
                      </p>
                    </div>
                  )}
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <span className="font-mono bg-gray-100 px-3 py-1 rounded text-sm flex-1">
                    {externalIds[platform.id]}
                  </span>
                  <button
                    onClick={() => copyId(platform.id)}
                    className="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded transition"
                    title="Copy Job ID"
                  >
                    <Copy size={16} />
                  </button>
                  <button
                    onClick={() => window.open(platform.url, '_blank')}
                    className="p-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded transition"
                    title={`Visit ${platform.name}`}
                  >
                    <ExternalLink size={16} />
                  </button>
                </div>
              )}
            </div>
          );
        })}
      </div>
      
      <div className="mt-4 p-3 bg-blue-50 rounded-lg">
        <p className="text-sm text-blue-800">
          <strong>💡 Tip:</strong> After posting to each platform, save the job ID here to track all your external postings in one place.
        </p>
      </div>
    </div>
  );
}
