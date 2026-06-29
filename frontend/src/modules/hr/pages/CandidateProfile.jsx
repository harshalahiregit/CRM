import { useState } from 'react'
import { useTheme } from '@/context/ThemeContext'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, Star, CheckCircle, XCircle, Clock, Video, Mail, Phone, MessageCircle, ChevronRight } from 'lucide-react'

const CANDIDATE = {
  id:1, name:'Arjun Sharma', role:'Senior React Developer', dept:'Engineering',
  email:'arjun.sharma@gmail.com', phone:'+91 98765 43210', location:'Bangalore',
  experience:'5 years', source:'LinkedIn', appliedDate:'10 Jun 2025',
  skills:['React.js','TypeScript','Node.js','GraphQL','AWS'],
  skillScores:{ 'React.js':92, 'TypeScript':85, 'Node.js':78, 'GraphQL':70, 'AWS':65 },
  rounds:[
    { name:'HR Telephonic', date:'14 Jun', interviewer:'Sunita Rao',    result:'Passed',    notes:'Good communication, culturally fit.' },
    { name:'Technical L1',  date:'17 Jun', interviewer:'Vikram Singh',  result:'Passed',    notes:'Strong in React, excellent problem solving.' },
    { name:'Manager L2',    date:'20 Jun', interviewer:'Deepak Iyer',   result:'Passed',    notes:'Leadership potential observed.' },
    { name:'Final HR L3',   date:'23 Jun', interviewer:'Sonal Mehta',   result:'Pending',   notes:'—' },
  ],
  assessment:{ score:88, date:'15 Jun', duration:'45 min', status:'Completed' },
  status:'Interview',
}

const AI_SUMMARY = {
  recommendation:'Highly Recommended',
  recColor:'#10b981',
  recBg:'rgba(16,185,129,0.12)',
  summary:'Arjun Sharma is a highly skilled full-stack developer with 5 years of experience in React and Node.js ecosystems. His technical proficiency, communication skills, and problem-solving ability make him an excellent candidate for the Senior React Developer role.',
  resumeMatch:87,
  strengths:['Strong React & TypeScript expertise','Excellent communication skills','Proven track record in agile teams','Leadership potential','Quick learner — adapts to new tech stack'],
  weaknesses:['Limited AWS experience','No prior managerial experience'],
  interviewSummary:'Consistently performed well across all rounds. Technical skills match 87% of job requirements. Cultural alignment confirmed.',
  nextAction:'Proceed to Offer Letter Generation',
}

const resultColor = r => r==='Passed'?{c:'#10b981',bg:'rgba(16,185,129,0.12)'}:r==='Pending'?{c:'#f59e0b',bg:'rgba(245,158,11,0.12)'}:{c:'#f87171',bg:'rgba(239,68,68,0.1)'}

export default function CandidateProfile() {
  const { isDark } = useTheme()
  const navigate   = useNavigate()
  const [decision, setDecision] = useState(null)

  const c = CANDIDATE
  const ai = AI_SUMMARY

  return (
    <div className="space-y-5 animate-[tiltIn_0.35s_ease_forwards]">
      {/* Back + header */}
      <div className="flex items-center gap-3">
        <button onClick={()=>navigate('/app/hr/candidates')} className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl transition-all" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}>
          <ArrowLeft size={12}/> Back
        </button>
        <ChevronRight size={12} style={{ color:'var(--text-muted)' }}/>
        <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{c.name}</span>
      </div>

      {/* Candidate header card */}
      <div className="card-3d relative overflow-hidden" style={{ padding:'24px' }}>
        <div className="absolute top-0 right-0 w-48 h-48 rounded-full opacity-[0.05] pointer-events-none" style={{ background:'radial-gradient(circle,#7C3AED,transparent)', transform:'translate(30%,-30%)' }}/>
        <div className="relative z-10 flex items-start gap-4 flex-wrap">
          <div className="w-16 h-16 rounded-2xl flex items-center justify-center text-xl font-black text-white flex-shrink-0" style={{ background:'linear-gradient(145deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow:'0 8px 24px rgba(124,58,237,0.4), inset 0 1px 0 rgba(255,255,255,0.25)' }}>
            {c.name.split(' ').map(n=>n[0]).join('')}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="font-black text-xl" style={{ color:'var(--text-h)', letterSpacing:'-0.02em' }}>{c.name}</h1>
              <span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background:'rgba(245,158,11,0.12)', color:'#fbbf24' }}>Interview Stage</span>
            </div>
            <p className="text-sm mt-1" style={{ color:'var(--text-muted)' }}>{c.role} · {c.dept}</p>
            <div className="flex items-center gap-4 mt-2 flex-wrap">
              <span className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Mail size={11}/>{c.email}</span>
              <span className="flex items-center gap-1.5 text-xs" style={{ color:'var(--text-muted)' }}><Phone size={11}/>{c.phone}</span>
              <span className="text-xs px-2 py-0.5 rounded-lg font-semibold" style={{ background:'rgba(124,58,237,0.1)', color:'#a78bfa' }}>{c.source}</span>
            </div>
          </div>
          <div className="flex gap-2">
            <button className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 3px 10px rgba(124,58,237,0.35)' }}><Video size={12}/> Schedule Meet</button>
            <button className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold" style={{ background:'var(--bg-input)', color:'var(--text-muted)', border:'1px solid var(--border)' }}><MessageCircle size={12}/> WhatsApp</button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Left — profile detail */}
        <div className="lg:col-span-2 space-y-5">
          {/* Skills */}
          <div className="card-3d" style={{ padding:'22px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>Skill Assessment</h3>
            <div className="space-y-3">
              {Object.entries(c.skillScores).map(([skill,score])=>(
                <div key={skill}>
                  <div className="flex justify-between mb-1.5">
                    <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>{skill}</span>
                    <span className="text-xs font-black" style={{ color:score>=85?'#10b981':score>=70?'#f59e0b':'#f87171' }}>{score}%</span>
                  </div>
                  <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
                    <div className="h-full rounded-full" style={{ width:`${score}%`, background:score>=85?'linear-gradient(90deg,#34d399,#10b981)':score>=70?'linear-gradient(90deg,#fcd34d,#f59e0b)':'linear-gradient(90deg,#f87171,#ef4444)' }}/>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Interview Rounds Timeline */}
          <div className="card-3d" style={{ padding:'22px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color:'var(--text-h)' }}>Interview Rounds</h3>
            <div className="space-y-4">
              {c.rounds.map((round,i)=>{
                const rc = resultColor(round.result)
                return(
                  <div key={i} className="flex gap-4">
                    <div className="flex flex-col items-center">
                      <div className="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black text-white flex-shrink-0" style={{ background:round.result==='Passed'?'linear-gradient(135deg,#10b981,#059669)':round.result==='Pending'?'linear-gradient(135deg,#f59e0b,#d97706)':'linear-gradient(135deg,#ef4444,#dc2626)' }}>{i+1}</div>
                      {i<c.rounds.length-1&&<div className="w-0.5 h-6 mt-1" style={{ background:'var(--border)' }}/>}
                    </div>
                    <div className="flex-1 pb-2">
                      <div className="flex items-center justify-between flex-wrap gap-2">
                        <div>
                          <p className="text-sm font-bold" style={{ color:'var(--text-h)' }}>{round.name}</p>
                          <p className="text-xs" style={{ color:'var(--text-muted)' }}>{round.date} · {round.interviewer}</p>
                        </div>
                        <span className="px-2.5 py-1 rounded-xl text-[10px] font-bold" style={{ background:rc.bg, color:rc.c }}>{round.result}</span>
                      </div>
                      {round.notes!=='—'&&<p className="text-xs mt-1.5 px-3 py-2 rounded-xl" style={{ background:'var(--bg-input)', color:'var(--text-muted)' }}>{round.notes}</p>}
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          {/* Assessment */}
          <div className="card-3d" style={{ padding:'22px' }}>
            <h3 className="font-bold text-sm mb-3" style={{ color:'var(--text-h)' }}>Online Assessment</h3>
            <div className="flex items-center gap-4 flex-wrap">
              <div className="w-16 h-16 rounded-2xl flex flex-col items-center justify-center" style={{ background:'linear-gradient(135deg,rgba(124,58,237,0.15),rgba(91,33,182,0.1))', border:'1px solid rgba(124,58,237,0.2)' }}>
                <span className="text-xl font-black" style={{ color:'#a78bfa' }}>{c.assessment.score}</span>
                <span className="text-[9px]" style={{ color:'var(--text-muted)' }}>/ 100</span>
              </div>
              <div>
                <p className="text-sm font-semibold" style={{ color:'var(--text-h)' }}>Score: <span style={{ color:'#a78bfa' }}>{c.assessment.score}/100</span></p>
                <p className="text-xs" style={{ color:'var(--text-muted)' }}>Date: {c.assessment.date} · Duration: {c.assessment.duration}</p>
                <span className="text-[10px] px-2 py-0.5 rounded-lg font-bold mt-1 inline-block" style={{ background:'rgba(16,185,129,0.12)', color:'#10b981' }}>{c.assessment.status}</span>
              </div>
            </div>
          </div>

          {/* Final Decision */}
          <div className="card-3d relative overflow-hidden" style={{ padding:'22px', borderColor:'rgba(124,58,237,0.25)' }}>
            <h3 className="font-bold text-base mb-4" style={{ color:'var(--text-h)' }}>Final Decision</h3>
            {decision&&<div className="mb-3 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background:decision==='Selected'?'rgba(16,185,129,0.12)':decision==='Hold'?'rgba(245,158,11,0.12)':'rgba(239,68,68,0.1)', color:decision==='Selected'?'#10b981':decision==='Hold'?'#fbbf24':'#f87171' }}>✓ Decision recorded: {decision}</div>}
            <div className="flex gap-3 flex-wrap">
              <button onClick={()=>setDecision('Selected')} className="flex-1 py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style={{ background:'linear-gradient(135deg,#34d399,#10b981,#059669)', boxShadow:'0 4px 14px rgba(16,185,129,0.4)' }} onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'} onMouseLeave={e=>e.currentTarget.style.transform='translateY(0)'}>
                <CheckCircle size={15} className="inline mr-1.5"/> Selected
              </button>
              <button onClick={()=>setDecision('Hold')} className="flex-1 py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style={{ background:'linear-gradient(135deg,#fcd34d,#f59e0b,#d97706)', boxShadow:'0 4px 14px rgba(245,158,11,0.4)' }} onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'} onMouseLeave={e=>e.currentTarget.style.transform='translateY(0)'}>
                <Clock size={15} className="inline mr-1.5"/> Hold
              </button>
              <button onClick={()=>setDecision('Rejected')} className="flex-1 py-3 rounded-xl text-sm font-bold text-white transition-all duration-200" style={{ background:'linear-gradient(135deg,#f87171,#ef4444,#dc2626)', boxShadow:'0 4px 14px rgba(239,68,68,0.4)' }} onMouseEnter={e=>e.currentTarget.style.transform='translateY(-2px)'} onMouseLeave={e=>e.currentTarget.style.transform='translateY(0)'}>
                <XCircle size={15} className="inline mr-1.5"/> Rejected
              </button>
            </div>
          </div>
        </div>

        {/* Right — AI Summary */}
        <div className="space-y-4">
          {/* AI Recommendation */}
          <div className="card-3d relative overflow-hidden" style={{ padding:'22px', borderColor:'rgba(124,58,237,0.3)', background:isDark?'linear-gradient(135deg,rgba(124,58,237,0.1),rgba(91,33,182,0.06))':'linear-gradient(135deg,rgba(124,58,237,0.06),rgba(167,139,250,0.04))' }}>
            <div className="absolute top-0 right-0 w-24 h-24 rounded-full opacity-10 pointer-events-none" style={{ background:'radial-gradient(#7C3AED,transparent)', transform:'translate(30%,-30%)' }}/>
            <div className="flex items-center gap-2 mb-3">
              <div className="w-8 h-8 rounded-xl flex items-center justify-center text-base" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 12px rgba(124,58,237,0.4)' }}>🤖</div>
              <div>
                <p className="text-xs font-bold" style={{ color:'var(--text-h)' }}>AI Summary</p>
                <p className="text-[10px]" style={{ color:'var(--text-muted)' }}>Powered by Sangoe AI</p>
              </div>
            </div>
            <div className="px-3 py-2 rounded-xl mb-3" style={{ background:ai.recBg, border:`1px solid ${ai.recColor}30` }}>
              <div className="flex items-center gap-2">
                <Star size={13} style={{ color:ai.recColor }}/>
                <span className="text-xs font-black" style={{ color:ai.recColor }}>{ai.recommendation}</span>
              </div>
            </div>
            <p className="text-xs leading-relaxed mb-4" style={{ color:'var(--text-muted)' }}>{ai.summary}</p>

            {/* Resume match */}
            <div className="mb-4">
              <div className="flex justify-between mb-1.5">
                <span className="text-xs font-semibold" style={{ color:'var(--text-muted)' }}>Skill Match</span>
                <span className="text-xs font-black" style={{ color:'#a78bfa' }}>{ai.resumeMatch}%</span>
              </div>
              <div className="h-2 rounded-full" style={{ background:'var(--bg-input)' }}>
                <div className="h-full rounded-full" style={{ width:`${ai.resumeMatch}%`, background:'linear-gradient(90deg,#a78bfa,#7C3AED)' }}/>
              </div>
            </div>

            {/* Strengths */}
            <div className="mb-3">
              <p className="text-[10px] font-black mb-2" style={{ color:'#10b981', letterSpacing:'0.06em', textTransform:'uppercase' }}>Strengths</p>
              <div className="space-y-1.5">
                {ai.strengths.map((s,i)=><div key={i} className="flex items-start gap-2 text-xs" style={{ color:'var(--text-muted)' }}><div className="w-1 h-1 rounded-full mt-1.5 flex-shrink-0" style={{ background:'#10b981' }}/>{s}</div>)}
              </div>
            </div>

            {/* Weaknesses */}
            <div className="mb-4">
              <p className="text-[10px] font-black mb-2" style={{ color:'#f87171', letterSpacing:'0.06em', textTransform:'uppercase' }}>Areas to Improve</p>
              <div className="space-y-1.5">
                {ai.weaknesses.map((w,i)=><div key={i} className="flex items-start gap-2 text-xs" style={{ color:'var(--text-muted)' }}><div className="w-1 h-1 rounded-full mt-1.5 flex-shrink-0" style={{ background:'#f87171' }}/>{w}</div>)}
              </div>
            </div>

            {/* Next Action */}
            <button className="w-full py-2.5 rounded-xl text-xs font-bold text-white" style={{ background:'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow:'0 4px 14px rgba(124,58,237,0.4)' }}>
              🚀 {ai.nextAction}
            </button>
          </div>

          {/* Interview Schedule panel */}
          <div className="card-3d" style={{ padding:'20px' }}>
            <h3 className="font-bold text-sm mb-3" style={{ color:'var(--text-h)' }}>Interview Schedule</h3>
            <div className="space-y-2">
              {[{ icon:'📹', label:'Google Meet Link', action:'Generate', color:'#3b82f6' },{ icon:'✉️', label:'Email to Candidate', action:'Send', color:'#6366f1' },{ icon:'💬', label:'WhatsApp Notification', action:'Send', color:'#25D366' },{ icon:'📅', label:'Calendar Event', action:'Create', color:'#f59e0b' },{ icon:'🔔', label:'Interview Reminder', action:'Set', color:'#a78bfa' },].map(item=>(
                <div key={item.label} className="flex items-center justify-between px-3 py-2.5 rounded-xl" style={{ background:'var(--bg-input)', border:'1px solid var(--border)' }}>
                  <div className="flex items-center gap-2.5">
                    <span className="text-sm">{item.icon}</span>
                    <span className="text-xs font-medium" style={{ color:'var(--text-muted)' }}>{item.label}</span>
                  </div>
                  <button className="text-[10px] font-bold px-2 py-0.5 rounded-lg" style={{ background:`${item.color}18`, color:item.color }}>{item.action}</button>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
