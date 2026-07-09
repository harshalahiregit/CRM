import { BrowserRouter } from 'react-router-dom'
import Providers from '@/app/providers'
import AppRoutes from '@/app/routes'
import ErrorBoundary from '@/components/ErrorBoundary'

export default function App() {
  return (
    <BrowserRouter>
      <ErrorBoundary>
        <Providers>
          <AppRoutes />
        </Providers>
      </ErrorBoundary>
    </BrowserRouter>
  )
}
