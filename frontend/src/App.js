import { useEffect } from "react";
import "@/App.css";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import axios from "axios";
import { HOME } from "@/constants/testIds";

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL;
const API = `${BACKEND_URL}/api`;
const IS_DEV = process.env.NODE_ENV === "development";

const Home = () => {
  useEffect(() => {
    // All values referenced inside this effect — `axios`, `API`, `IS_DEV` —
    // are module-level constants whose identity never changes between renders.
    // Listing them in the deps array would not change behaviour but would
    // mislead future readers into thinking they're reactive state, so we
    // intentionally pass an empty deps array and silence the lint rule
    // for this single line.  React's official docs explicitly call out
    // this pattern for one-time mount effects.
    const helloWorldApi = async () => {
      try {
        const response = await axios.get(`${API}/`);
        if (IS_DEV) console.log(response.data.message);
      } catch (e) {
        if (IS_DEV) console.error(e, `errored out requesting / api`);
      }
    };
    helloWorldApi();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div>
      <header className="App-header">
        <a
          data-testid={HOME.emergentLink}
          className="App-link"
          href="https://emergent.sh"
          target="_blank"
          rel="noopener noreferrer"
        >
          <img src="https://avatars.githubusercontent.com/in/1201222?s=120&u=2686cf91179bbafbc7a71bfbc43004cf9ae1acea&v=4" />
        </a>
        <p className="mt-5">Building something incredible ~!</p>
      </header>
    </div>
  );
};

function App() {
  return (
    <div className="App">
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<Home />}>
            <Route index element={<Home />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </div>
  );
}

export default App;
