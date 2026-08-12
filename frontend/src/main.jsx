import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import './index.css'
// Register Quill's STYLE attributors (font-size/color/align emit inline styles
// the sanitizer keeps, instead of classes it strips) ONCE at startup — before
// any editor mounts — so rich-text size/colour always survive save + re-edit,
// regardless of which editor happens to load first.
import '@/lib/quillConfig'
import App from './App.jsx'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 1000 * 60 * 5,   // 5 minutes
      refetchOnWindowFocus: false,
    },
  },
})

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
)
