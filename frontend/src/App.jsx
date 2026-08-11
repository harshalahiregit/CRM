import { BrowserRouter } from 'react-router-dom'
import Providers from '@/app/providers'
import AppRoutes from '@/app/routes'
import ErrorBoundary from '@/components/ErrorBoundary'
import MediaLightbox from '@/components/ui/MediaLightbox'

export default function App() {
  return (
    <BrowserRouter>
      <ErrorBoundary>
        <Providers>
          <AppRoutes />
          {/* Global click-to-enlarge lightbox for images/videos in all rich-content areas */}
          <MediaLightbox />
        </Providers>
      </ErrorBoundary>
    </BrowserRouter>
  )
}
